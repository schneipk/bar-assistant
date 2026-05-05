<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Requests;

use Illuminate\Validation\Rule;
use Kami\Cocktail\Rules\ValidCurrency;
use Illuminate\Foundation\Http\FormRequest;

class PriceCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => 'required',
            'currency' => ['required', 'size:3', new ValidCurrency()],
            'is_base_category' => ['sometimes', 'boolean'],
            'suggestion_provider' => ['nullable', 'string', Rule::in(['external-json', 'rumundco-search', 'amazon-search'])],
            'suggestion_config' => ['nullable', 'array'],
            'suggestion_config.url_template' => ['nullable', 'required_if:suggestion_provider,external-json,rumundco-search,amazon-search', 'string'],
            'suggestion_config.items_path' => ['nullable', 'string'],
            'suggestion_config.name_path' => ['nullable', 'string'],
            'suggestion_config.price_path' => ['nullable', 'required_if:suggestion_provider,external-json', 'string'],
            'suggestion_config.price_is_minor' => ['nullable', 'boolean'],
            'suggestion_config.url_path' => ['nullable', 'string'],
            'suggestion_config.amount_path' => ['nullable', 'string'],
            'suggestion_config.units_path' => ['nullable', 'string'],
            'suggestion_config.default_amount' => ['nullable', 'numeric'],
            'suggestion_config.default_units' => ['nullable', 'string'],
            'suggestion_config.skip_non_alcoholic' => ['nullable', 'boolean'],
            'suggestion_config.refine_with_strength_above_results' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
