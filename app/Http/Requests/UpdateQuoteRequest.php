<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer.first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'customer.last_name'  => ['nullable', 'string', 'max:100'],
            'customer.email'      => ['sometimes', 'required', 'email', 'max:190'],
            'customer.phone'      => ['nullable', 'string', 'max:30'],
            'customer.company_name' => ['nullable', 'string', 'max:190'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.state' => ['nullable', 'string', 'max:100'],
            'customer.country' => ['nullable', 'string', 'max:100'],
            'customer.zip' => ['nullable', 'string', 'max:20'],

            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'], // existing item_id hoy to update, nahi to nayo item
            'items.*.shopify_product_id' => ['nullable', 'string'],
            'items.*.shopify_variant_id' => ['nullable', 'string'],
            'items.*.product_title'      => ['required_with:items', 'string', 'max:255'],
            'items.*.variant_title'      => ['nullable', 'string', 'max:255'],
            'items.*.sku'                => ['nullable', 'string', 'max:100'],
            'items.*.image_url'          => ['nullable', 'url'],
            'items.*.quantity'           => ['required_with:items', 'integer', 'min:1'],
            'items.*.original_price'     => ['required_with:items', 'numeric', 'min:0'],
            'items.*.customer_requested_price' => ['nullable', 'numeric', 'min:0'],

            'items.*.discount_type'  => ['nullable', 'in:percentage,fixed'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],

            'items.*.properties'         => ['nullable', 'array', 'max:20'],
            'items.*.properties.*.name'  => ['required', 'string', 'max:100'], 
            'items.*.properties.*.value' => ['nullable', 'string', 'max:255'],

            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'in:product_page,collection_page,cart_page'],

            'discount_type' => ['nullable', 'in:amount,fixed,percentage'],
            'discount_value' => ['nullable', 'numeric', 'min:0', Rule::when($this->input('discount_type') === 'percentage', ['max:100'])],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'discount_show_on_draft_order' => ['nullable', 'boolean'],
            'discount_show_on_email' => ['nullable', 'boolean'],
            'discount_show_on_invoice_pdf' => ['nullable', 'boolean'],
            'discount_show_on_pdf' => ['nullable', 'boolean'],
 
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'shipping_name' => ['nullable', 'string', 'max:100'],
 
            'tax_type' => ['nullable', 'in:amount,fixed,percentage'],
            'tax_value' => ['nullable', 'numeric', 'min:0', Rule::when($this->input('tax_type') === 'percentage', ['max:100'])],
            'tax_name' => ['nullable', 'string', 'max:15'],
 
            'merchant_note' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date'],

            'field_values' => ['nullable', 'array'],
            'field_values.*.field_id' => ['required_with:field_values', 'integer'],
            'field_values.*.value'    => ['nullable', 'string', 'max:2000'],
        ];
    }
}