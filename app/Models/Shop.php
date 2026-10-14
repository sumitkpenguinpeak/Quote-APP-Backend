<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Class Shop
 *
 * Represents a merchant store that has installed Review Pro App.
 *
 * @property int $id
 * @property string $shop
 * @property string|null $access_token
 * @property string|null $scope
 * @property string|null $shopify_plan
 * @property string $billing_status
 * @property string|null $billing_id
 * @property string $plan
 * @property \Illuminate\Support\Carbon|null $plan_selected_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $installed_at
 * @property \Illuminate\Support\Carbon|null $uninstalled_at
 */
class Shop extends Model
{
    use HasFactory;

    public const BILLING_STATUS_PENDING = 'pending';
    public const BILLING_STATUS_ACTIVE = 'active';
    public const BILLING_STATUS_DECLINED = 'declined';
    public const BILLING_STATUS_CANCELLED = 'cancelled';
    public const BILLING_STATUS_EXPIRED = 'expired';

    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'scope',
        'shopify_plan',
        'billing_status',
        'billing_id',
        'plan',
        'plan_selected_at',
        'is_active',
        'installed_at',
        'uninstalled_at',
    ];

    /**
     * Attributes hidden from array / JSON representation.
     * The access token must never leak to the frontend.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Encrypted at rest with APP_KEY.
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'is_active' => 'boolean',
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'plan_selected_at' => 'datetime',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    /**
     * Normalize the shop domain whenever it is set (lowercase, trimmed).
     */
    protected function shop(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower(trim($value)),
        );
    }

    public function settings()
    {
        return $this->hasOne(ShopSetting::class, 'shop_id');
    }

    /** Gmail account the merchant connected for sending quote emails (Settings > Email Configuration). */
    public function emailAccount()
    {
        return $this->hasOne(ShopEmailAccount::class, 'shop_id');
    }

    /**
     * Install pachhi merchant e pricing page par plan select karyo che ke nahi.
     */
    public function hasSelectedPlan(): bool
    {
        return $this->plan_selected_at !== null;
    }

    /**
     * Whether the shop currently has an active, paid subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->is_active && $this->billing_status === self::BILLING_STATUS_ACTIVE;
    }

    /**
     * Key of the plan the shop is on right now (free | starter | pro).
     * A paid plan only counts while its Shopify subscription is active.
     */
    public function planKey(): string
    {
        $default = config('shopify.billing.default_plan', 'free');

        if (! $this->hasActiveSubscription()) {
            return $default;
        }

        return array_key_exists((string) $this->plan, config('shopify.plans', []))
            ? $this->plan
            : $default;
    }

    /**
     * Config of the current plan (name, price, quote_limit, features).
     */
    public function planConfig(): array
    {
        return config('shopify.plans.' . $this->planKey(), []);
    }

    /**
     * Whether the current plan unlocks the given feature.
     */
    public function canUse(string $feature): bool
    {
        return in_array($feature, $this->planConfig()['features'] ?? [], true);
    }

    /**
     * Quotes allowed per calendar month on the current plan (null = unlimited).
     */
    public function quoteLimit(): ?int
    {
        $limit = $this->planConfig()['quote_limit'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Quotes created in the current calendar month.
     */
    public function quotesThisMonth(): int
    {
        return Quote::where('shop_id', $this->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function hasReachedQuoteLimit(): bool
    {
        $limit = $this->quoteLimit();

        return $limit !== null && $this->quotesThisMonth() >= $limit;
    }

    /**
     * Scope a query to only installed (active) shops.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Shopify "write_X" scope "read_X" ne pan cover kare chhe, ane token response ma
     * ghani var matra write_X j aave chhe. Etle write_X hoy to read_X pan granted gano.
     * $granted khali hoy (juna records) to block nathi karta.
     */
    public static function scopeGranted(?string $granted, string $needed): bool
    {
        $have = array_filter(array_map('trim', explode(',', (string) $granted)));
        if (empty($have)) {
            return true;
        }
        if (in_array($needed, $have, true)) {
            return true;
        }
        if (str_starts_with($needed, 'read_') && in_array('write_' . substr($needed, 5), $have, true)) {
            return true;
        }

        return false;
    }
}
