<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IngredientPriceSuggestion extends Model
{
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\IngredientPriceSuggestionFactory> */
    use HasFactory;

    protected $fillable = [
        'ingredient_id',
        'price_category_id',
        'provider_name',
        'status',
        'search_query',
        'match_name',
        'external_url',
        'price',
        'amount',
        'units',
        'confidence',
        'meta',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'confidence' => 'float',
            'meta' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<PriceCategory, $this>
     */
    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
