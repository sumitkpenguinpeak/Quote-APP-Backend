<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extra_settings' => [
                'nullable',
                'array',
                'max:1000000',
            ],
        ];
        // return [
        //     // 'button_text' => ['nullable', 'string', 'max:60'],
        //     // 'button_color' => ['nullable', 'string', 'max:20'],
        //     // 'button_text_color' => ['nullable', 'string', 'max:20'],
        //     // 'button_position' => ['nullable', 'string', 'max:60'],
        //     // 'show_on_product_page' => ['boolean'],
        //     // 'show_on_collection_page' => ['boolean'],
        //     // 'show_on_cart_page' => ['boolean'],
        //     // 'hide_price_globally' => ['boolean'],
        //     // 'hide_add_to_cart_globally' => ['boolean'],
        //     // 'hidden_price_replacement_text' => ['nullable', 'string', 'max:190'],
        //     // 'allow_multiple_products_per_quote' => ['boolean'],
        //     // 'allow_customer_suggest_price' => ['boolean'],
        //     // 'allow_quantity_edit' => ['boolean'],
        //     // 'quote_expiry_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        //     // 'auto_create_draft_order_on_approve' => ['boolean'],
        //     // 'notification_email' => ['nullable', 'email'],
        //     // 'notify_merchant_on_new_quote' => ['boolean'],
        //     // 'notify_customer_on_status_change' => ['boolean'],
        //     'extra_settings' => ['nullable', 'string', 'max:100000000000000']
        // ];
    }
}
