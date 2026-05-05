<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models;

use Brick\Money\RationalMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kami\Cocktail\Models\ValueObjects\UnitValueObject;
use Kami\Cocktail\Models\ValueObjects\AmountValueObject;
use Kami\Cocktail\Models\ValueObjects\ResolvedIngredientPrice;

class CocktailIngredient extends Model
{
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\CocktailIngredientFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $casts = [
        'is_specified' => 'boolean',
        'optional' => 'boolean',
        'amount' => 'float',
        'amount_max' => 'float',
    ];

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Cocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    /**
     * @return HasMany<CocktailIngredientSubstitute, $this>
     */
    public function substitutes(): HasMany
    {
        return $this->hasMany(CocktailIngredientSubstitute::class);
    }

    public function userHasInShelfAsSubstitute(User $user): bool
    {
        $currentShelf = $user->getShelfIngredients($this->ingredient->bar_id);

        foreach ($this->substitutes as $sub) {
            if ($currentShelf->contains('ingredient_id', $sub->ingredient_id)) {
                return true;
            }
        }

        return false;
    }

    public function barHasInShelfAsSubstitute(): bool
    {
        $currentShelf = $this->ingredient->bar->shelfIngredients;

        foreach ($this->substitutes as $sub) {
            if ($currentShelf->contains('ingredient_id', $sub->ingredient_id)) {
                return true;
            }
        }

        return false;
    }

    public function getAmount(): AmountValueObject
    {
        return new AmountValueObject(
            $this->amount,
            new UnitValueObject($this->units),
            $this->amount_max,
        );
    }

    /**
     * Return the price per use in the given price category.
     * Converts ingredient amount to match the price amount if possible.
     *
     * @param PriceCategory $priceCategory
     */
    public function getConvertedPricePerUse(PriceCategory $priceCategory): ?RationalMoney
    {
        $resolvedPrice = $this->resolvePrice($priceCategory);

        if ($resolvedPrice === null) {
            return null;
        }

        $convertedLocalAmount = $resolvedPrice->amount->convertTo(new UnitValueObject($resolvedPrice->ingredientPrice->units));

        try {
            $pricePerUse = $resolvedPrice->ingredientPrice->getPricePerUnit()->multipliedBy($convertedLocalAmount->amountMin);
        } catch (\Throwable) {
            return null;
        }

        if ($pricePerUse->isLessThanOrEqualTo(0)) {
            $pricePerUse = $pricePerUse->plus(0.01);
        }

        return $pricePerUse;
    }

    public function getMinConvertedPriceInCategory(PriceCategory $priceCategory): ?IngredientPrice
    {
        return $this->resolvePrice($priceCategory)?->ingredientPrice;
    }

    public function resolvePrice(PriceCategory $priceCategory): ?ResolvedIngredientPrice
    {
        foreach ($this->getPriceCandidates($priceCategory) as $candidate) {
            $resolvedPrice = $this->resolvePriceForIngredient(
                $candidate['ingredient'],
                $candidate['amount'],
                $candidate['categories'],
                $candidate['source'],
            );

            if ($resolvedPrice !== null) {
                return $resolvedPrice;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{ingredient: Ingredient, amount: AmountValueObject, categories: array<int, PriceCategory>, source: string}>
     */
    private function getPriceCandidates(PriceCategory $priceCategory): array
    {
        $baseCategories = $this->getBasePriceCategories($priceCategory);
        $variants = $this->ingredient->children;
        $variantsInShelf = $variants->filter(fn (Ingredient $variant) => $variant->barHasInShelf());
        $remainingVariants = $variants->reject(fn (Ingredient $variant) => $variant->barHasInShelf());
        $substitutes = $this->substitutes;
        $substitutesInShelf = $substitutes->filter(fn (CocktailIngredientSubstitute $substitute) => $substitute->barHasInShelf());
        $remainingSubstitutes = $substitutes->reject(fn (CocktailIngredientSubstitute $substitute) => $substitute->barHasInShelf());
        $candidates = [];

        if (!$this->ingredient->barHasInShelf()) {
            foreach ($variantsInShelf as $variant) {
                $candidates[] = [
                    'ingredient' => $variant,
                    'amount' => $this->getAmount(),
                    'categories' => [$priceCategory],
                    'source' => 'variant',
                ];
            }

            foreach ($variantsInShelf as $variant) {
                $candidates[] = [
                    'ingredient' => $variant,
                    'amount' => $this->getAmount(),
                    'categories' => $baseCategories,
                    'source' => 'variant_base_category',
                ];
            }

            foreach ($substitutesInShelf as $substitute) {
                $candidates[] = [
                    'ingredient' => $substitute->ingredient,
                    'amount' => $this->getAmountForSubstitute($substitute),
                    'categories' => [$priceCategory],
                    'source' => 'substitute',
                ];
            }

            foreach ($substitutesInShelf as $substitute) {
                $candidates[] = [
                    'ingredient' => $substitute->ingredient,
                    'amount' => $this->getAmountForSubstitute($substitute),
                    'categories' => $baseCategories,
                    'source' => 'substitute_base_category',
                ];
            }
        }

        $candidates[] = [
            'ingredient' => $this->ingredient,
            'amount' => $this->getAmount(),
            'categories' => [$priceCategory],
            'source' => 'original',
        ];

        $candidates[] = [
            'ingredient' => $this->ingredient,
            'amount' => $this->getAmount(),
            'categories' => $baseCategories,
            'source' => 'base_category',
        ];

        foreach ($remainingVariants as $variant) {
            $candidates[] = [
                'ingredient' => $variant,
                'amount' => $this->getAmount(),
                'categories' => [$priceCategory],
                'source' => 'variant',
            ];
        }

        foreach ($remainingVariants as $variant) {
            $candidates[] = [
                'ingredient' => $variant,
                'amount' => $this->getAmount(),
                'categories' => $baseCategories,
                'source' => 'variant_base_category',
            ];
        }

        foreach ($remainingSubstitutes as $substitute) {
            $candidates[] = [
                'ingredient' => $substitute->ingredient,
                'amount' => $this->getAmountForSubstitute($substitute),
                'categories' => [$priceCategory],
                'source' => 'substitute',
            ];
        }

        foreach ($remainingSubstitutes as $substitute) {
            $candidates[] = [
                'ingredient' => $substitute->ingredient,
                'amount' => $this->getAmountForSubstitute($substitute),
                'categories' => $baseCategories,
                'source' => 'substitute_base_category',
            ];
        }

        return $candidates;
    }

    /**
     * @param array<int, PriceCategory> $categories
     */
    private function resolvePriceForIngredient(Ingredient $ingredient, AmountValueObject $amount, array $categories, string $source): ?ResolvedIngredientPrice
    {
        foreach ($categories as $category) {
            $ingredientPrice = $this->findMinPriceForIngredientInCategory($ingredient, $amount, $category);

            if ($ingredientPrice !== null) {
                return new ResolvedIngredientPrice($ingredient, $ingredientPrice, $amount, $source);
            }
        }

        return null;
    }

    private function findMinPriceForIngredientInCategory(Ingredient $ingredient, AmountValueObject $amount, PriceCategory $priceCategory): ?IngredientPrice
    {
        return $ingredient
            ->getPricesWithConvertedUnits($amount->units->value)
            ->sortBy('price')
            ->where('price_category_id', $priceCategory->id)
            ->where('units', $amount->units->value)
            ->first();
    }

    /**
     * @return array<int, PriceCategory>
     */
    private function getBasePriceCategories(PriceCategory $priceCategory): array
    {
        return PriceCategory::query()
            ->where('bar_id', $priceCategory->bar_id)
            ->where('currency', $priceCategory->currency)
            ->where('is_base_category', true)
            ->where('id', '!=', $priceCategory->id)
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function getAmountForSubstitute(CocktailIngredientSubstitute $substitute): AmountValueObject
    {
        if ($substitute->amount !== null && $substitute->units !== null) {
            return new AmountValueObject(
                $substitute->amount,
                new UnitValueObject($substitute->units),
                $substitute->amount_max,
            );
        }

        return $this->getAmount();
    }
}
