<?php

declare(strict_types=1);

namespace Kami\Cocktail\PriceSuggestions\Providers;

use DOMDocument;
use DOMXPath;
use Throwable;
use Brick\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;
use Kami\Cocktail\Models\IngredientPrice;
use Kami\Cocktail\Models\ValueObjects\IngredientPriceSuggestionCandidate;
use Kami\Cocktail\PriceSuggestions\Contracts\IngredientPriceSuggestionProviderInterface;

class RumundcoSearchPriceSuggestionProvider implements IngredientPriceSuggestionProviderInterface
{
    public function suggest(Ingredient $ingredient, PriceCategory $priceCategory): array
    {
        if ($priceCategory->suggestion_provider !== 'rumundco-search') {
            return [];
        }

        $config = is_array($priceCategory->suggestion_config) ? $priceCategory->suggestion_config : [];
        $urlTemplate = trim((string) ($config['url_template'] ?? 'https://www.rumundco.de/search?search={query}'));
        $resultThreshold = max(1, (int) ($config['refine_with_strength_above_results'] ?? 12));

        $query = $this->buildQuery($ingredient, $priceCategory, includeStrength: false);
        $html = $this->fetchHtml($urlTemplate, $query, $ingredient, $priceCategory);
        if ($html === null) {
            return [];
        }

        if (($ingredient->strength ?? 0) > 0 && $this->countResultNodes($html) > $resultThreshold) {
            $refinedQuery = $this->buildQuery($ingredient, $priceCategory, includeStrength: true);
            $refinedHtml = $this->fetchHtml($urlTemplate, $refinedQuery, $ingredient, $priceCategory);

            if ($refinedHtml !== null && $this->countResultNodes($refinedHtml) > 0) {
                $query = $refinedQuery;
                $html = $refinedHtml;
            }
        }

        return $this->findCandidates($html, $ingredient, $priceCategory, (bool) ($config['skip_non_alcoholic'] ?? true), $query);
    }

    private function fetchHtml(string $urlTemplate, string $query, Ingredient $ingredient, PriceCategory $priceCategory): ?string
    {
        $url = strtr($urlTemplate, ['{query}' => rawurlencode($query)]);

        try {
            return Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'BarAssistant/LocalDev Price Suggestion Bot',
                    'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                ])
                ->get($url)
                ->throw()
                ->body();
        } catch (Throwable $throwable) {
            Log::warning('Rum&Co price suggestion provider failed', [
                'ingredient_id' => $ingredient->id,
                'price_category_id' => $priceCategory->id,
                'url' => $url,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function buildQuery(Ingredient $ingredient, PriceCategory $priceCategory, bool $includeStrength): string
    {
        $parts = [$ingredient->name];

        if ($ingredient->distillery) {
            $parts[] = $ingredient->distillery;
        }

        if ($includeStrength && $ingredient->strength) {
            $parts[] = rtrim(rtrim(number_format($ingredient->strength, 2, '.', ''), '0'), '.') . '%';
        }

        $basePrice = $this->getBasePriceFallback($ingredient, $priceCategory);
        if ($basePrice !== null && $basePrice->amount > 0 && $basePrice->units) {
            $parts[] = $this->formatAmountForSearch((float) $basePrice->amount, (string) $basePrice->units);
        }

        return implode(' ', array_filter($parts));
    }

    private function countResultNodes(string $html): int
    {
        $xpath = $this->createXPath($html);
        if ($xpath === null) {
            return 0;
        }

        $nodes = $xpath->query('//div[contains(@class, "product-box-inner")]');

        return $nodes === false ? 0 : $nodes->length;
    }

    private function findCandidates(string $html, Ingredient $ingredient, PriceCategory $priceCategory, bool $skipNonAlcoholic, string $searchQuery): array
    {
        $xpath = $this->createXPath($html);
        if ($xpath === null) {
            return [];
        }

        $nodes = $xpath->query('//div[contains(@class, "product-box-inner")]');
        if ($nodes === false) {
            return [];
        }

        $candidates = [];
        $seenKeys = [];

        foreach ($nodes as $index => $node) {
            $titleNode = $xpath->query('.//a[contains(@class, "product-title")][1]', $node)?->item(0);
            $priceNode = $xpath->query('.//div[contains(@class, "price")][1]', $node)?->item(0);
            $imageNode = $xpath->query('.//img[1]', $node)?->item(0);

            if ($titleNode === null || $priceNode === null) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', $titleNode->textContent ?? ''));
            $priceText = trim((string) preg_replace('/\s+/u', ' ', $priceNode->textContent ?? ''));
            $url = $titleNode->attributes?->getNamedItem('href')?->nodeValue;
            $imageUrl = $this->normalizeImageUrl($imageNode?->attributes?->getNamedItem('src')?->nodeValue
                ?? $imageNode?->attributes?->getNamedItem('data-src')?->nodeValue);

            if ($name === '' || $priceText === '') {
                continue;
            }

            if ($skipNonAlcoholic && $ingredient->strength > 0 && Str::contains(Str::lower($name), ['alkoholfrei', 'zero'])) {
                continue;
            }

            $priceMinor = $this->parseEuroPriceToMinor($priceText, $priceCategory->currency);
            if ($priceMinor === null) {
                continue;
            }

            [$amount, $units] = $this->extractAmountAndUnits($name, $ingredient, $priceCategory);
            $score = $this->scoreCandidate($name, $ingredient, $amount, $units, $priceCategory);

            $dedupeKey = ($url ?: $name) . '|' . $priceMinor;
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;

            $candidates[] = [
                'score' => $score,
                'candidate' => new IngredientPriceSuggestionCandidate(
                    providerName: 'rumundco-search',
                    searchQuery: $searchQuery,
                    matchName: $name,
                    externalUrl: $url,
                    price: $priceMinor,
                    amount: $amount,
                    units: $units,
                    confidence: min(0.95, max(0.35, $score / 20)),
                    meta: [
                        'reason' => 'Fetched from Rum&Co search results',
                        'image_url' => $imageUrl,
                        'result_index' => $index,
                        'score' => $score,
                    ],
                ),
            ];
        }

        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(
            fn (array $entry) => $entry['candidate'],
            array_slice($candidates, 0, 5),
        );
    }

    private function createXPath(string $html): ?DOMXPath
    {
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if ($loaded === false) {
            return null;
        }

        return new DOMXPath($document);
    }

    private function scoreCandidate(string $name, Ingredient $ingredient, float $amount, string $units, PriceCategory $priceCategory): int
    {
        $score = 0;
        $lowerName = Str::lower($name);

        if (Str::contains($lowerName, Str::lower($ingredient->name))) {
            $score += 6;
        }

        if ($ingredient->distillery && Str::contains($lowerName, Str::lower($ingredient->distillery))) {
            $score += 8;
        }

        if ($ingredient->strength) {
            $strength = rtrim(rtrim(number_format($ingredient->strength, 2, '.', ''), '0'), '.');
            if (Str::contains($lowerName, Str::lower($strength . '%'))) {
                $score += 4;
            }
        }

        $basePrice = $this->getBasePriceFallback($ingredient, $priceCategory);
        if ($basePrice !== null && $this->amountMatches((float) $basePrice->amount, (string) $basePrice->units, $amount, $units)) {
            $score += 3;
        }

        return $score;
    }

    private function amountMatches(float $expectedAmount, string $expectedUnits, float $actualAmount, string $actualUnits): bool
    {
        return abs($expectedAmount - $actualAmount) < 0.01 && Str::lower($expectedUnits) === Str::lower($actualUnits);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function extractAmountAndUnits(string $name, Ingredient $ingredient, PriceCategory $priceCategory): array
    {
        if (preg_match('/([0-9]+(?:[\.,][0-9]+)?)\s*(l|ml)\b/ui', $name, $matches) === 1) {
            $amount = (float) str_replace(',', '.', $matches[1]);
            $units = Str::lower($matches[2]);

            if ($units === 'l') {
                return [$amount * 1000, 'ml'];
            }

            return [$amount, 'ml'];
        }

        $basePrice = $this->getBasePriceFallback($ingredient, $priceCategory);
        if ($basePrice !== null) {
            return [(float) $basePrice->amount, (string) $basePrice->units];
        }

        return [700.0, 'ml'];
    }

    private function parseEuroPriceToMinor(string $priceText, string $currency): ?int
    {
        if (preg_match('/([0-9]+(?:,[0-9]{2})?)/u', $priceText, $matches) !== 1) {
            return null;
        }

        return Money::of(str_replace(',', '.', $matches[1]), $currency)->getMinorAmount()->toInt();
    }

    private function normalizeImageUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, '/')) {
            return 'https://www.rumundco.de' . $url;
        }

        return $url;
    }

    private function formatAmountForSearch(float $amount, string $units): string
    {
        $units = Str::lower($units);

        if ($units === 'ml') {
            $liters = $amount / 1000;

            return rtrim(rtrim(number_format($liters, 2, ',', ''), '0'), ',') . 'l';
        }

        return (string) (int) round($amount) . $units;
    }

    private function getBasePriceFallback(Ingredient $ingredient, PriceCategory $priceCategory): ?IngredientPrice
    {
        $baseCategory = PriceCategory::query()
            ->where('bar_id', $ingredient->bar_id)
            ->where('currency', $priceCategory->currency)
            ->where('is_base_category', true)
            ->orderBy('name')
            ->first();

        if ($baseCategory === null) {
            return null;
        }

        /** @var IngredientPrice|null */
        return $ingredient->prices
            ->where('price_category_id', $baseCategory->id)
            ->sortBy('price')
            ->first();
    }
}