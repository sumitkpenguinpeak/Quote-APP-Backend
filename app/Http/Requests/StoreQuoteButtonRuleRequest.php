<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteButtonRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'applies_to' => ['required', 'in:all_products,specific_products,specific_collections,specific_tags'],
            'target_ids' => ['nullable', 'array'],
            'hide_price' => ['boolean'],
            'hide_add_to_cart' => ['boolean'],
            'show_quote_button' => ['boolean'],
            'priority' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ];
    }
}
