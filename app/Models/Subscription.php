<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'shop_id', 'plan_name', 'price', 'shopify_charge_id', 'status',
        'trial_days', 'trial_ends_at', 'activated_at', 'cancelled_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
