<?php

declare(strict_types=1);

namespace Kami\Cocktail\PriceSuggestions\Providers;

use Throwable;
use Brick\Money\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;
use Kami\Cocktail\Models\IngredientPrice;
use Kami\Cocktail\Models\ValueObjects\IngredientPriceSuggestionCandidate;
use Kami\Cocktail\PriceSuggestions\Contracts\IngredientPriceSuggestionProviderInterface;

class ExternalJsonPriceSuggestionProvider implements IngredientPriceSuggestionProviderInterface
{
    public function suggest(Ingredient $ingredient, PriceCategory $priceCategory): array
    {
        if ($priceCategory->suggestion_provider !== 'external-json') {
            return [];
        }

        $config = is_array($priceCategory->suggestion_config) ? $priceCategory->suggestion_config : [];
        $urlTemplate = trim((string) ($config['url_template'] ?? ''));
        $pricePath = trim((string) ($config['price_path'] ?? ''));

        if ($urlTemplate === '' || $pricePath === '') {
            return [];
        }

        $query = $this->buildQuery($ingredient, $priceCategory);
        $url = $this->interpolateTemplate($urlTemplate, [
            'query' => $query,
            'ingredient_name' => $ingredient->name,
            'distillery' => (string) ($ingredient->distillery ?? ''),
            'strength' => $ingredient->strength !== null ? (string) $ingredient->strength : '',
            'currency' => $priceCategory->currency,
        ]);

        try {
            $payload = Http::acceptJson()->timeout(8)->get($url)->throw()->json();
        } catch (Throwable $throwable) {
            Log::warning('External price suggestion provider failed', [
                'ingredient_id' => $ingredient->id,
                'price_category_id' => $priceCategory->id,
                'url' => $url,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }

        $items = $this->resolveItems($payload, $config['items_path'] ?? null);
        if ($items === []) {
            return [];
        }

        $fallbackPrice = $this->getBasePriceFallback($ingredient, $priceCategory);

        $candidates = [];
        foreach ($items as $index => $item) {
            $priceValue = Arr::get($item, $pricePath);
            if ($priceValue === null || $priceValue === '') {
                continue;
            }

            $amount = Arr::get($item, $config['amount_path'] ?? '') ?? $config['default_amount'] ?? $fallbackPrice?->amount;
            $units = Arr::get($item, $config['units_path'] ?? '') ?? $config['default_units'] ?? $fallbackPrice?->units;

            if ($amount === null || $units === null) {
                continue;
            }

            $candidates[] = new IngredientPriceSuggestionCandidate(
                providerName: 'external-json',
                searchQuery: $query,
                matchName: (string) (Arr::get($item, $config['name_path'] ?? 'name') ?? $ingredient->name),
                externalUrl: ($externalUrl = Arr::get($item, $config['url_path'] ?? 'url')) ? (string) $externalUrl : null,
                price: $this->normalizePrice($priceValue, $priceCategory->currency, (bool) ($config['price_is_minor'] ?? false)),
                amount: (float) $amount,
                units: (string) $units,
                confidence: max(0.3, 0.7 - ($index * 0.05)),
                meta: [
                    'reason' => 'Fetched from external JSON provider',
                    'provider_url' => $url,
                    'result_index' => $index,
                ],
            );
        }

        return array_slice($candidates, 0, 5);
    }

    private function buildQuery(Ingredient $ingredient, PriceCategory $priceCategory): string
    {
        $basePrice = $this->getBasePriceFallback($ingredient, $priceCategory);

        $queryParts = [$ingredient->name];
        if ($ingredient->distillery) {
            $queryParts[] = $ingredient->distillery;
        }
        if ($ingredient->strength) {
            $queryParts[] = rtrim(rtrim(number_format($ingredient->strength, 2, '.', ''), '0'), '.') . '%';
        }
        if ($basePrice && $basePrice->amount > 0 && $basePrice->units) {
            $queryParts[] = (string) (int) round($basePrice->amount) . $basePrice->units;
        }

        return implode(' ', array_filter($queryParts));
    }

    /**
     * @param array<mixed>|null $payload
     * @return array<mixed>|null
     */
    private function resolveItems(mixed $payload, mixed $itemsPath): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $result = $payload;
        if (is_string($itemsPath) && trim($itemsPath) !== '') {
            $resolved = Arr::get($payload, $itemsPath);
            if (is_array($resolved)) {
                $result = $resolved;
            }
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, is_array(...)));
        }

        return [$result];
    }

    private function normalizePrice(mixed $priceValue, string $currency, bool $isMinor): int
    {
        if ($isMinor) {
            return (int) round((float) $priceValue);
        }

        return Money::of((string) $priceValue, $currency)->getMinorAmount()->toInt();
    }

    private function interpolateTemplate(string $template, array $replacements): string
    {
        $map = [];

        foreach ($replacements as $key => $value) {
            $map['{' . $key . '}'] = rawurlencode((string) $value);
        }

        return strtr($template, $map);
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
