<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kami\Cocktail\Models\IngredientPriceSuggestion>
 */
class IngredientPriceSuggestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ingredient_id' => \Kami\Cocktail\Models\Ingredient::factory(),
            'price_category_id' => \Kami\Cocktail\Models\PriceCategory::factory(),
            'provider_name' => 'base-category-seed',
            'status' => 'pending',
            'search_query' => fake()->words(3, true),
            'match_name' => fake()->words(2, true),
            'external_url' => fake()->optional()->url(),
            'price' => fake()->optional()->numberBetween(500, 3000),
            'amount' => fake()->optional()->randomElement([500, 700, 750, 1000]),
            'units' => fake()->optional()->randomElement(['ml', 'oz']),
            'confidence' => fake()->randomFloat(2, 0.1, 0.95),
            'meta' => ['reason' => 'factory'],
            'accepted_at' => null,
        ];
    }
}
