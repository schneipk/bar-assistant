<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefreshIngredientPriceSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price_category_id' => ['nullable', 'integer'],
        ];
    }
}
