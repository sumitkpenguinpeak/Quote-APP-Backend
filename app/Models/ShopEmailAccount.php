<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The email account (Gmail) a merchant connected so quote emails are sent
 * from their own address instead of the app's default mailer.
 *
 * @property int $id
 * @property int $shop_id
 * @property string $provider
 * @property string $email
 * @property string $access_token   encrypted at rest
 * @property string $refresh_token  encrypted at rest
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string $status
 * @property string|null $last_error
 */
class ShopEmailAccount extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REAUTH_REQUIRED = 'reauth_required';

    protected $fillable = [
        'shop_id', 'provider', 'email', 'access_token', 'refresh_token',
        'expires_at', 'status', 'last_error',
    ];

    // Tokens must never leak to the frontend.
    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
