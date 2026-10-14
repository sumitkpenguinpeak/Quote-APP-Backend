<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id', 'shopify_product_id', 'shopify_variant_id', 'product_title', 'variant_title',
        'sku', 'image_url', 'quantity', 'original_price', 'customer_requested_price',
        'merchant_offered_price', 'final_price', 'discount_type', 'discount_value', 'line_total', 'note','properties'
    ];

    protected $casts = ['properties' => 'array'];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
