<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use Brick\Math\RoundingMode;
use OpenApi\Attributes as OAT;
use Brick\Money\Context\DefaultContext;
use Kami\Cocktail\Models\CocktailIngredient;
use Kami\Cocktail\Models\ValueObjects\Price;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Kami\Cocktail\Models\CocktailPrice
 */
#[OAT\Schema(
    schema: 'CocktailPrice',
    description: 'Cocktail price resource',
    properties: [
        new OAT\Property(property: 'missing_prices_count', type: 'integer', example: 2, description: 'Number of ingredients that are missing defined prices in this category'),
        new OAT\Property(property: 'price_category', type: PriceCategoryResource::class),
        new OAT\Property(property: 'total_price', type: PriceResource::class, description: 'Total cocktail price, sum of `price_per_pour` amounts'),
        new OAT\Property(property: 'prices_per_ingredient', type: 'array', items: new OAT\Items(type: 'object', required: ['ingredient', 'priced_ingredient', 'price_source', 'price_per_unit', 'price_per_use', 'units'], properties: [
            new OAT\Property(property: 'ingredient', type: IngredientBasicResource::class),
            new OAT\Property(property: 'priced_ingredient', type: IngredientBasicResource::class, description: 'Ingredient actually used for price resolution'),
            new OAT\Property(property: 'price_source', type: 'string', example: 'variant', description: 'How the price was resolved (`original`, `base_category`, `variant`, `variant_base_category`, `substitute`, `substitute_base_category`)'),
            new OAT\Property(property: 'units', type: 'string', description: 'Units used for price calculation'),
            new OAT\Property(property: 'price_per_unit', type: PriceResource::class, description: 'Price per 1 unit of ingredient amount'),
            new OAT\Property(property: 'price_per_use', type: PriceResource::class, description: 'Price per cocktail ingredient part'),
        ]), description: 'Prices per each ingredient.'),
    ],
    required: ['missing_prices_count', 'price_category', 'total_price', 'prices_per_ingredient']
)]
class CocktailPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray($request)
    {
        $prices = $this->cocktail->ingredients->map(function (CocktailIngredient $cocktailIngredient) {
            $resolvedPrice = $cocktailIngredient->resolvePrice($this->priceCategory);
            if ($resolvedPrice === null) {
                return null;
            }

            return [
                'units' => $resolvedPrice->ingredientPrice->getAmount()->units,
                'ingredient' => new IngredientBasicResource($cocktailIngredient->ingredient),
                'priced_ingredient' => new IngredientBasicResource($resolvedPrice->ingredient),
                'price_source' => $resolvedPrice->source,
                'price_per_unit' => new PriceResource(new Price($resolvedPrice->ingredientPrice->getPricePerUnit($resolvedPrice->amount->units->value)->to(new DefaultContext(), RoundingMode::DOWN))),
                'price_per_use' => new PriceResource(new Price($cocktailIngredient->getConvertedPricePerUse($this->priceCategory)->to(new DefaultContext(), RoundingMode::DOWN))),
            ];
        })->filter()->values();

        return [
            'missing_prices_count' => $this->cocktail->ingredients->count() - $prices->count(),
            'price_category' => new PriceCategoryResource($this->priceCategory),
            'total_price' => new PriceResource(new Price($this->cocktail->calculatePrice($this->priceCategory))),
            'prices_per_ingredient' => $prices,
        ];
    }
}
