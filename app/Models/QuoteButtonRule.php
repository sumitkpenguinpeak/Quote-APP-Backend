<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteButtonRule extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'applies_to', 'target_ids', 'hide_price', 'hide_add_to_cart',
        'show_quote_button', 'priority', 'is_active',
    ];

    protected $casts = [
        'target_ids' => 'array',
        'hide_price' => 'boolean',
        'hide_add_to_cart' => 'boolean',
        'show_quote_button' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
