<?php

declare(strict_types=1);

namespace Kami\Cocktail\PriceSuggestions\Contracts;

use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;

interface IngredientPriceSuggestionProviderInterface
{
    /**
     * @return array<int, \Kami\Cocktail\Models\ValueObjects\IngredientPriceSuggestionCandidate>
     */
    public function suggest(Ingredient $ingredient, PriceCategory $priceCategory): array;
}
