<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptIngredientPriceSuggestionRequest extends FormRequest
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
            'price' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'units' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ];
    }
}
