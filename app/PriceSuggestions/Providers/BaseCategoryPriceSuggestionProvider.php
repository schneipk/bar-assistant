<?php

declare(strict_types=1);

namespace Kami\Cocktail\PriceSuggestions\Providers;

use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;
use Kami\Cocktail\Models\ValueObjects\IngredientPriceSuggestionCandidate;
use Kami\Cocktail\PriceSuggestions\Contracts\IngredientPriceSuggestionProviderInterface;

class BaseCategoryPriceSuggestionProvider implements IngredientPriceSuggestionProviderInterface
{
    public function suggest(Ingredient $ingredient, PriceCategory $priceCategory): array
    {
        $baseCategory = PriceCategory::query()
            ->where('bar_id', $ingredient->bar_id)
            ->where('currency', $priceCategory->currency)
            ->where('is_base_category', true)
            ->orderBy('name')
            ->first();

        if ($baseCategory === null) {
            return [];
        }

        $basePrice = $ingredient->prices
            ->where('price_category_id', $baseCategory->id)
            ->sortBy('price')
            ->first();

        if ($basePrice === null) {
            return [];
        }

        $queryParts = [$ingredient->name];
        if ($ingredient->distillery) {
            $queryParts[] = $ingredient->distillery;
        }
        if ($ingredient->strength) {
            $queryParts[] = rtrim(rtrim(number_format($ingredient->strength, 2, '.', ''), '0'), '.') . '%';
        }
        if ($basePrice->amount > 0 && $basePrice->units) {
            $queryParts[] = (string) (int) round($basePrice->amount) . $basePrice->units;
        }

        return [
            new IngredientPriceSuggestionCandidate(
                providerName: 'base-category-seed',
                searchQuery: implode(' ', array_filter($queryParts)),
                matchName: $ingredient->name,
                externalUrl: null,
                price: $basePrice->price,
                amount: (float) $basePrice->amount,
                units: $basePrice->units,
                confidence: 0.45,
                meta: [
                    'reason' => 'Seeded from base price category',
                    'base_price_category_id' => $baseCategory->id,
                    'base_price_category_name' => $baseCategory->name,
                ],
            ),
        ];
    }
}
