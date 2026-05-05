<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use OpenApi\Attributes as OAT;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Kami\Cocktail\Models\PriceCategory
 */
#[OAT\Schema(
    schema: 'PriceCategory',
    description: 'Price category',
    properties: [
        new OAT\Property(type: 'integer', example: 1, property: 'id'),
        new OAT\Property(type: 'string', example: 'Amazon (DE)', property: 'name'),
        new OAT\Property(type: 'string', nullable: true, example: 'Current price on amazon.de', property: 'description'),
        new OAT\Property(type: 'string', example: 'EUR', format: 'ISO 4217', property: 'currency'),
        new OAT\Property(type: 'string', example: '€', property: 'currency_symbol'),
        new OAT\Property(type: 'boolean', example: false, property: 'is_base_category'),
        new OAT\Property(type: 'string', nullable: true, example: 'external-json', property: 'suggestion_provider'),
        new OAT\Property(property: 'suggestion_config', type: 'object', nullable: true),
    ],
    required: ['id', 'name', 'description', 'currency', 'is_base_category']
)]
class PriceCategoryResource extends JsonResource
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => $this->currency,
            'currency_symbol' => '',
            'is_base_category' => (bool) $this->is_base_category,
            'suggestion_provider' => $this->suggestion_provider,
            'suggestion_config' => $this->suggestion_config,
        ];
    }
}
