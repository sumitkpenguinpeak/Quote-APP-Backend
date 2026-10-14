<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopify_product_id' => $this->shopify_product_id,
            'shopify_variant_id' => $this->shopify_variant_id,
            'product_title' => $this->product_title,
            'variant_title' => $this->variant_title,
            'sku' => $this->sku,
            'image_url' => $this->image_url,
            'quantity' => $this->quantity,
            'original_price' => (float) $this->original_price,
            'customer_requested_price' => $this->customer_requested_price !== null ? (float) $this->customer_requested_price : null,
            'merchant_offered_price' => $this->merchant_offered_price !== null ? (float) $this->merchant_offered_price : null,
            'final_price' => $this->final_price !== null ? (float) $this->final_price : null,
            'line_total' => (float) $this->line_total,
            'discount_type'  => $this->discount_type,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'properties' => $this->properties ?? [],
        ];
    }
}
