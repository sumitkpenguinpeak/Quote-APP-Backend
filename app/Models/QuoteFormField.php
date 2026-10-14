<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteFormField extends Model
{
    protected $fillable = [
        'shop_id', 'label', 'key', 'field_type', 'options', 'placeholder',
        'is_required', 'is_default', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
