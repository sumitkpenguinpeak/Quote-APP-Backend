<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'product_id',
        'product_handle',
        'name',
        'email',
        'rating',
        'review_title',
        'review_body',
        'review_file',
        'recommend',
        'shop_id',
    ];

    protected $casts = [
        'recommend' => 'boolean',
    ];
}