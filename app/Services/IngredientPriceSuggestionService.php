<?php

declare(strict_types=1);

namespace Kami\Cocktail\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;
use Illuminate\Database\DatabaseManager;
use Kami\Cocktail\Models\IngredientPrice;
use Kami\Cocktail\Models\IngredientPriceSuggestion;
use Kami\Cocktail\OpenAPI\Schemas\IngredientRequest;
use Kami\Cocktail\OpenAPI\Schemas\IngredientPriceRequest;
use Kami\Cocktail\PriceSuggestions\Providers\AmazonSearchPriceSuggestionProvider;
use Kami\Cocktail\PriceSuggestions\Providers\BaseCategoryPriceSuggestionProvider;
use Kami\Cocktail\PriceSuggestions\Providers\ExternalJsonPriceSuggestionProvider;
use Kami\Cocktail\PriceSuggestions\Providers\RumundcoSearchPriceSuggestionProvider;
use Kami\Cocktail\PriceSuggestions\Contracts\IngredientPriceSuggestionProviderInterface;
use Kami\Cocktail\Services\Image\ImageService;

class IngredientPriceSuggestionService
{
    /**
     * @var array<int, IngredientPriceSuggestionProviderInterface>
     */
    private array $providers;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly IngredientService $ingredientService,
        private readonly ImageService $imageService,
    )
    {
        $this->providers = [
            app(RumundcoSearchPriceSuggestionProvider::class),
            app(AmazonSearchPriceSuggestionProvider::class),
            app(ExternalJsonPriceSuggestionProvider::class),
            app(BaseCategoryPriceSuggestionProvider::class),
        ];
    }

    /**
     * @return Collection<int, IngredientPriceSuggestion>
     */
    public function refreshSuggestions(Ingredient $ingredient, ?int $priceCategoryId = null): Collection
    {
        $ingredient->loadMissing('prices.priceCategory');

        $categories = PriceCategory::query()
            ->where('bar_id', $ingredient->bar_id)
            ->where('is_base_category', false)
            ->when($priceCategoryId !== null, fn ($query) => $query->where('id', $priceCategoryId))
            ->orderBy('name')
            ->get();

        $this->db->transaction(function () use ($ingredient, $categories) {
            IngredientPriceSuggestion::query()
                ->where('ingredient_id', $ingredient->id)
                ->whereIn('price_category_id', $categories->pluck('id'))
                ->where('status', 'pending')
                ->delete();

            foreach ($categories as $category) {
                if ($ingredient->prices->where('price_category_id', $category->id)->isNotEmpty()) {
                    continue;
                }

                foreach ($this->providers as $provider) {
                    if (!$this->shouldRunProviderForCategory($provider, $category)) {
                        continue;
                    }

                    foreach ($provider->suggest($ingredient, $category) as $candidate) {
                        IngredientPriceSuggestion::create([
                            'ingredient_id' => $ingredient->id,
                            'price_category_id' => $category->id,
                            'provider_name' => $candidate->providerName,
                            'status' => 'pending',
                            'search_query' => $candidate->searchQuery,
                            'match_name' => $candidate->matchName,
                            'external_url' => $candidate->externalUrl,
                            'price' => $candidate->price,
                            'amount' => $candidate->amount,
                            'units' => $candidate->units,
                            'confidence' => $candidate->confidence,
                            'meta' => $candidate->meta,
                        ]);
                    }
                }
            }
        });

        return $ingredient->priceSuggestions()
            ->with('priceCategory')
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();
    }

    public function acceptSuggestion(IngredientPriceSuggestion $suggestion, array $overrides = []): IngredientPrice
    {
        $price = $overrides['price'] ?? $suggestion->price;
        $amount = $overrides['amount'] ?? $suggestion->amount;
        $units = $overrides['units'] ?? $suggestion->units;
        $description = $overrides['description'] ?? $this->buildDescription($suggestion);

        if ($price === null || $amount === null || $units === null) {
            abort(422, 'Suggestion must include price, amount and units before it can be accepted.');
        }

        /** @var IngredientPrice */
        $ingredientPrice = $this->db->transaction(function () use ($suggestion, $price, $amount, $units, $description) {
            $ingredientPrice = IngredientPrice::create([
                'ingredient_id' => $suggestion->ingredient_id,
                'price_category_id' => $suggestion->price_category_id,
                'price' => $price,
                'amount' => $amount,
                'units' => $units,
                'description' => $description,
            ]);

            $suggestion->status = 'accepted';
            $suggestion->accepted_at = Carbon::now();
            $suggestion->save();

            return $ingredientPrice;
        });

        return $ingredientPrice->load('priceCategory');
    }

    public function createVariantFromSuggestion(IngredientPriceSuggestion $suggestion, int $userId): Ingredient
    {
        $suggestion->loadMissing(['ingredient.images', 'priceCategory']);

        $imageIds = [];
        $imageUrl = $suggestion->meta['image_url'] ?? null;
        if (is_string($imageUrl) && $imageUrl !== '') {
            $image = $this->imageService->importRemoteImage(
                $imageUrl,
                $userId,
                is_string($suggestion->external_url) ? $suggestion->external_url : null,
            );

            if ($image !== null) {
                $imageIds[] = $image->id;
            }
        }

        $priceRequests = [];
        if ($suggestion->price !== null && $suggestion->amount !== null && $suggestion->units !== null) {
            $priceRequests[] = new IngredientPriceRequest(
                priceCategoryId: $suggestion->price_category_id,
                price: $suggestion->price,
                amount: $suggestion->amount,
                units: $suggestion->units,
                description: $this->buildDescription($suggestion),
            );
        }

        $ingredient = $this->ingredientService->createIngredient(new IngredientRequest(
            barId: $suggestion->ingredient->bar_id,
            name: $suggestion->match_name ?: $suggestion->ingredient->name,
            userId: $userId,
            strength: $this->resolveVariantStrength($suggestion),
            description: $this->buildVariantDescription($suggestion),
            origin: $suggestion->ingredient->origin,
            color: $suggestion->ingredient->color,
            parentIngredientId: $suggestion->ingredient_id,
            images: $imageIds,
            complexIngredientParts: [],
            prices: $priceRequests,
            calculatorId: $suggestion->ingredient->calculator_id,
            sugarContent: $suggestion->ingredient->sugar_g_per_ml,
            acidity: $suggestion->ingredient->acidity,
            distillery: $this->resolveVariantDistillery($suggestion),
            units: $suggestion->ingredient->units ?? $suggestion->units,
        ));

        $suggestion->status = 'accepted';
        $suggestion->accepted_at = Carbon::now();
        $suggestion->save();

        return $ingredient->load('images', 'prices.priceCategory', 'parentIngredient');
    }

    private function shouldRunProviderForCategory(IngredientPriceSuggestionProviderInterface $provider, PriceCategory $category): bool
    {
        if ($category->suggestion_provider === 'rumundco-search') {
            return $provider instanceof RumundcoSearchPriceSuggestionProvider;
        }

        if ($category->suggestion_provider === 'amazon-search') {
            return $provider instanceof AmazonSearchPriceSuggestionProvider;
        }

        if ($category->suggestion_provider === 'external-json') {
            return $provider instanceof ExternalJsonPriceSuggestionProvider;
        }

        return $provider instanceof BaseCategoryPriceSuggestionProvider;
    }

    private function buildDescription(IngredientPriceSuggestion $suggestion): string
    {
        $parts = array_filter([
            'Suggested via ' . $suggestion->provider_name,
            $suggestion->match_name,
            $suggestion->external_url,
        ]);

        return implode(' | ', $parts);
    }

    private function buildVariantDescription(IngredientPriceSuggestion $suggestion): ?string
    {
        $parts = array_filter([
            $suggestion->ingredient->description,
            $suggestion->external_url ? 'Produktquelle: ' . $suggestion->external_url : null,
        ]);

        if ($parts === []) {
            return null;
        }

        return implode("\n\n", $parts);
    }

    private function resolveVariantStrength(IngredientPriceSuggestion $suggestion): float
    {
        $strength = $suggestion->meta['strength'] ?? null;
        if (is_numeric($strength)) {
            return (float) $strength;
        }

        if (is_string($suggestion->match_name) && preg_match('/([0-9]+(?:[\\.,][0-9]+)?)\s*%/u', $suggestion->match_name, $matches) === 1) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return (float) ($suggestion->ingredient->strength ?? 0.0);
    }

    private function resolveVariantDistillery(IngredientPriceSuggestion $suggestion): ?string
    {
        $distillery = $suggestion->meta['distillery'] ?? null;
        if (is_string($distillery) && $distillery !== '') {
            return $distillery;
        }

        if (is_string($suggestion->ingredient->distillery) && $suggestion->ingredient->distillery !== '' && is_string($suggestion->match_name)) {
            if (str_contains(mb_strtolower($suggestion->match_name), mb_strtolower($suggestion->ingredient->distillery))) {
                return $suggestion->ingredient->distillery;
            }
        }

        if (is_string($suggestion->match_name) && preg_match('/^([^\s]+)(?:\s|$)/u', $suggestion->match_name, $matches) === 1) {
            $candidate = trim($matches[1]);
            if ($candidate !== '' && !preg_match('/^[0-9]+(?:[\.,][0-9]+)?$/', $candidate)) {
                return $candidate;
            }
        }

        return $suggestion->ingredient->distillery;
    }
}
