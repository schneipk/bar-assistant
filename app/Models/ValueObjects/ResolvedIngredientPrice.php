<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\ValueObjects;

use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\IngredientPrice;

final readonly class ResolvedIngredientPrice
{
    public function __construct(
        public Ingredient $ingredient,
        public IngredientPrice $ingredientPrice,
        public AmountValueObject $amount,
        public string $source,
    ) {
    }
}
