<?php

declare(strict_types=1);

namespace Kami\Cocktail\Jobs;

use Illuminate\Bus\Queueable;
use Kami\Cocktail\Models\Ingredient;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Kami\Cocktail\Services\IngredientPriceSuggestionService;

class GenerateIngredientPriceSuggestions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $ingredientId,
        private readonly ?int $priceCategoryId = null,
    ) {
    }

    public function handle(IngredientPriceSuggestionService $ingredientPriceSuggestionService): void
    {
        $ingredient = Ingredient::with('prices.priceCategory')->findOrFail($this->ingredientId);

        $ingredientPriceSuggestionService->refreshSuggestions($ingredient, $this->priceCategoryId);
    }
}
