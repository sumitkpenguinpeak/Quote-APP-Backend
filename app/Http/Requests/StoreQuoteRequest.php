<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // storefront-public endpoint, shop resolved via middleware
    }

    public function rules(): array
    {
        return [
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name'  => ['nullable', 'string', 'max:100'],
            'customer.email'      => ['required', 'email', 'max:190'],
            'customer.phone'      => ['nullable', 'string', 'max:30'],
            'customer.company_name' => ['nullable', 'string', 'max:190'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.state' => ['nullable', 'string', 'max:100'],
            'customer.country' => ['nullable', 'string', 'max:100'],
            'customer.zip' => ['nullable', 'string', 'max:20'],
            'customer.shopify_customer_id' => ['nullable', 'string', 'max:50'],
            // shipping rate lookup mate (Customer table ma save nathi thata)
            'customer.country_code' => ['nullable', 'string', 'max:10'],
            'customer.state_code' => ['nullable', 'string', 'max:10'],

            // customer e select kareli Shopify shipping rate (price server par pachho calculate thay chhe)
            'shipping' => ['nullable', 'array'],
            'shipping.handle' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.shopify_product_id' => ['required', 'string'],
            'items.*.shopify_variant_id' => ['nullable', 'string'],
            'items.*.product_title'      => ['required', 'string', 'max:255'],
            'items.*.variant_title'      => ['nullable', 'string', 'max:255'],
            'items.*.sku'                => ['nullable', 'string', 'max:100'],
            'items.*.image_url'          => ['nullable', 'url'],
            'items.*.quantity'           => ['required', 'integer', 'min:1'],
            'items.*.original_price'     => ['required', 'numeric', 'min:0'],
            'items.*.customer_requested_price' => ['nullable', 'numeric', 'min:0'],

            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'in:product_page,collection_page,cart_page'],

            // dynamic custom form field answers: [{ "field_id": 3, "value": "..." }]
            'field_values' => ['nullable', 'array'],
            'field_values.*.field_id' => ['required_with:field_values', 'integer'],
            'field_values.*.value'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one product is required to submit a quote.',
            'customer.email.required' => 'Customer email is required.',
        ];
    }
}