<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class WebhookLog
 *
 * Stores an audit trail of every webhook Review Pro App receives from Shopify.
 *
 * @property int $id
 * @property string $shop
 * @property string $topic
 * @property array|null $payload
 * @property \Illuminate\Support\Carbon $received_at
 */
class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop',
        'topic',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];
}
