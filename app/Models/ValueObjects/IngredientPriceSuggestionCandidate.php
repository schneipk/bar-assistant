<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models\ValueObjects;

final readonly class IngredientPriceSuggestionCandidate
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $providerName,
        public string $searchQuery,
        public ?string $matchName,
        public ?string $externalUrl,
        public ?int $price,
        public ?float $amount,
        public ?string $units,
        public float $confidence,
        public array $meta = [],
    ) {
    }
}
