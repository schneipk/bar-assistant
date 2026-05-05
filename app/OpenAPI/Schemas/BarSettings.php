<?php

declare(strict_types=1);

namespace Kami\Cocktail\OpenAPI\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema()]
class BarSettings
{
    #[OAT\Property(property: 'default_units')]
    public ?string $defaultUnits = null;
    #[OAT\Property(property: 'default_currency')]
    public ?string $defaultCurrency = null;
    #[OAT\Property(property: 'target_pour_cost', type: 'number', format: 'float', nullable: true)]
    public ?float $targetPourCost = null;

    /**
     * @return array<string, string|float|null>
     */
    public function toArray(): array
    {
        return [
            'default_units' => $this->defaultUnits,
            'default_currency' => $this->defaultCurrency,
            'target_pour_cost' => $this->targetPourCost,
        ];
    }
}
