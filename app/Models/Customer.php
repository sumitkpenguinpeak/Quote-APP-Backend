<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'shop_id', 'shopify_customer_id', 'first_name', 'last_name', 'email', 'phone',
        'address', 'city', 'state', 'country', 'zip',
        'company_name', 'vat_number', 'company_details', 'is_b2b',
    ];

    protected $casts = [
        'company_details' => 'array',
        'is_b2b' => 'boolean',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class);
    }
}