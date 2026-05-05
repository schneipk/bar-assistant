<?php

declare(strict_types=1);

namespace Kami\Cocktail\OpenAPI\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(required: ['name', 'currency'])]
class PriceCategoryRequest
{
    #[OAT\Property(example: 'Amazon (DE)')]
    public string $name;
    #[OAT\Property(example: 'Current price on amazon.de')]
    public ?string $description = null;
    #[OAT\Property(example: 'EUR', format: 'ISO 4217')]
    public string $currency;
    #[OAT\Property(example: false)]
    public bool $is_base_category = false;
    #[OAT\Property(example: 'external-json', nullable: true)]
    public ?string $suggestion_provider = null;
    #[OAT\Property(nullable: true, properties: [
        new OAT\Property(property: 'url_template', type: 'string', example: 'https://example.com/search?q={query}'),
        new OAT\Property(property: 'items_path', type: 'string', nullable: true, example: 'items'),
        new OAT\Property(property: 'name_path', type: 'string', nullable: true, example: 'name'),
        new OAT\Property(property: 'price_path', type: 'string', nullable: true, example: 'price'),
        new OAT\Property(property: 'price_is_minor', type: 'boolean', nullable: true, example: false),
        new OAT\Property(property: 'url_path', type: 'string', nullable: true, example: 'url'),
        new OAT\Property(property: 'amount_path', type: 'string', nullable: true, example: 'size.amount'),
        new OAT\Property(property: 'units_path', type: 'string', nullable: true, example: 'size.units'),
        new OAT\Property(property: 'default_amount', type: 'number', format: 'float', nullable: true, example: 700),
        new OAT\Property(property: 'default_units', type: 'string', nullable: true, example: 'ml'),
        new OAT\Property(property: 'refine_with_strength_above_results', type: 'integer', nullable: true, example: 12),
    ])]
    public ?array $suggestion_config = null;
}
