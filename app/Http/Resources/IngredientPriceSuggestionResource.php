<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use OpenApi\Attributes as OAT;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Kami\Cocktail\Models\IngredientPriceSuggestion
 */
#[OAT\Schema(
    schema: 'IngredientPriceSuggestion',
    description: 'Suggested price candidate for an ingredient',
    required: ['id', 'ingredient', 'price_category', 'provider_name', 'status', 'confidence', 'created_at', 'updated_at'],
    properties: [
        new OAT\Property(property: 'id', type: 'integer', example: 1),
        new OAT\Property(property: 'ingredient', type: IngredientBasicResource::class),
        new OAT\Property(property: 'price_category', type: PriceCategoryResource::class),
        new OAT\Property(property: 'provider_name', type: 'string', example: 'base-category-seed'),
        new OAT\Property(property: 'status', type: 'string', example: 'pending'),
        new OAT\Property(property: 'search_query', type: 'string', nullable: true, example: 'Gin 700ml'),
        new OAT\Property(property: 'match_name', type: 'string', nullable: true, example: 'Dry Gin'),
        new OAT\Property(property: 'external_url', type: 'string', nullable: true, example: 'https://example.com/product'),
        new OAT\Property(property: 'image_url', type: 'string', nullable: true, example: 'https://example.com/product.webp'),
        new OAT\Property(property: 'suggested_price_minor', type: 'integer', nullable: true, example: 1599),
        new OAT\Property(property: 'suggested_amount', type: 'number', format: 'float', nullable: true, example: 700),
        new OAT\Property(property: 'suggested_units', type: 'string', nullable: true, example: 'ml'),
        new OAT\Property(property: 'confidence', type: 'number', format: 'float', example: 0.45),
        new OAT\Property(property: 'meta', type: 'object'),
        new OAT\Property(property: 'accepted_at', type: 'string', format: 'date-time', nullable: true),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class IngredientPriceSuggestionResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'ingredient' => new IngredientBasicResource($this->relationLoaded('ingredient') ? $this->ingredient : $this->ingredient()->first()),
            'price_category' => new PriceCategoryResource($this->relationLoaded('priceCategory') ? $this->priceCategory : $this->priceCategory()->first()),
            'provider_name' => $this->provider_name,
            'status' => $this->status,
            'search_query' => $this->search_query,
            'match_name' => $this->match_name,
            'external_url' => $this->external_url,
            'image_url' => $this->meta['image_url'] ?? null,
            'suggested_price_minor' => $this->price,
            'suggested_amount' => $this->amount,
            'suggested_units' => $this->units,
            'confidence' => $this->confidence,
            'meta' => $this->meta ?? [],
            'accepted_at' => $this->accepted_at?->toAtomString(),
            'created_at' => $this->created_at->toAtomString(),
            'updated_at' => $this->updated_at->toAtomString(),
        ];
    }
}
