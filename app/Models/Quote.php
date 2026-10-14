<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    protected $fillable = [
        'shop_id', 'customer_id', 'assigned_staff_id', 'quote_number', 'status', 'source',
        'currency', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount_reason',
        'discount_show_on_draft_order', 'discount_show_on_email', 'discount_show_on_invoice_pdf',
        'discount_show_on_pdf', 'shipping_price', 'shipping_name', 'tax_type', 'tax_value',
        'tax_amount', 'tax_name', 'due_date', 'total',
        'customer_note', 'merchant_note', 'shopify_draft_order_id', 'shopify_draft_order_gid',
        'shopify_order_id', 'checkout_url', 'pdf_path', 'viewed_at', 'expires_at', 'approved_at',
        'converted_at', 'rejected_at', 'invoice_number', 'invoice_issued_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'tax_value' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_show_on_draft_order' => 'boolean',
        'discount_show_on_email' => 'boolean',
        'discount_show_on_invoice_pdf' => 'boolean',
        'discount_show_on_pdf' => 'boolean',
        'due_date' => 'date',
        'viewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'converted_at' => 'datetime',
        'invoice_issued_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_VIEWED = 'viewed';
    const STATUS_NEGOTIATING = 'negotiating';
    const STATUS_APPROVED = 'approved';
    const STATUS_CONVERTED = 'converted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Subtotal, discount, tax ane total ek j jagyae thi calculate kare chhe
     * (frontend na computeTotals() jevi j rit):
     *   subtotal = items na line_total no sum (quoted price x qty)
     *   discount = percentage (subtotal par) ke fixed amount, subtotal thi vadhu nahi
     *   tax      = percentage (subtotal - discount par) ke fixed amount
     *   total    = subtotal - discount + shipping + tax
     *
     * discount_type / tax_type: 'percentage' ke 'fixed' (frontend no "amount" = 'fixed').
     * Save karva mate pachhi ->save() call karo.
     */
    public function recalculateTotals(): self
    {
        $subtotal = round((float) $this->items()->sum('line_total'), 2);

        $discountValue = max(0, (float) $this->discount_value);
        $discount = $this->discount_type === 'percentage'
            ? $subtotal * min($discountValue, 100) / 100
            : $discountValue;
        $discount = round(min($discount, $subtotal), 2);

        $shipping = round(max(0, (float) $this->shipping_price), 2);

        $taxValue = max(0, (float) $this->tax_value);
        $taxBase = $subtotal - $discount;
        $tax = $this->tax_type === 'percentage'
            ? $taxBase * min($taxValue, 100) / 100
            : $taxValue;
        $tax = round($tax, 2);

        $this->subtotal = $subtotal;
        $this->discount_amount = $discount;
        $this->tax_amount = $tax;
        $this->total = round($subtotal - $discount + $shipping + $tax, 2);

        return $this;
    }

    /**
     * Shop na prefix/suffix setting pramane navo quote number. Count-based start, pan je number
     * already vaparayo hoy (delete / duplicate pachhi) tene skip karine hamesha unique aapse.
     */
    public static function nextQuoteNumber(Shop $shop): string
    {
        $extra = $shop->settings->extra_settings ?? [];
        if (is_string($extra)) {
            $extra = json_decode($extra, true) ?: [];
        }
        $prefix = (string) ($extra['prefix'] ?? '');
        $suffix = (string) ($extra['suffix'] ?? '');

        $n = static::where('shop_id', $shop->id)->count() + 1001;
        do {
            $number = ($prefix !== '' && $suffix !== '')
                ? $prefix . $n . $suffix
                : 'Q-' . $n . '-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(3));
            $n++;
        } while (static::where('quote_number', $number)->exists()); // quote_number globally unique chhe (unique index)

        return $number;
    }

    /**
     * Invoice number ek vaar j banave chhe (pachhi same rahe chhe) - Settings > Formatting standards >
     * "PDF invoice" format pramane. Tokens: {{order_date}} / {{create_date}} (Y-m-d) ane {{quote_id}} (quote no numeric id).
     * Default format: IW{{order_date}}-{{quote_id}}  ->  IW2026-10-09-42
     */
    public function assignInvoiceNumber(Shop $shop): string
    {
        if (filled($this->invoice_number)) {
            return $this->invoice_number;
        }

        $extra = $shop->settings->extra_settings ?? [];
        if (is_string($extra)) {
            $extra = json_decode($extra, true) ?: [];
        }
        $format = trim((string) ($extra['invoiceFormat'] ?? '')) ?: 'IW{{order_date}}-{{quote_id}}';

        $issuedAt = now();
        $tokens = [
            'order_date' => $issuedAt->format('Y-m-d'),
            'create_date' => $issuedAt->format('Y-m-d'),
            'quote_id' => (string) $this->id,
        ];
        $number = preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn ($m) => $tokens[strtolower($m[1])] ?? '',
            $format
        );

        // Same shop ma duplicate na thay (format ma {{quote_id}} na hoy to pan)
        $candidate = $number;
        $i = 2;
        while (static::where('shop_id', $this->shop_id)
            ->where('invoice_number', $candidate)
            ->where('id', '!=', $this->id)
            ->exists()) {
            $candidate = $number . '-' . $i++;
        }

        $this->forceFill(['invoice_number' => $candidate, 'invoice_issued_at' => $issuedAt])->save();

        return $candidate;
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(QuoteFieldValue::class);
    }

    public function messages()
    {
        return $this->hasMany(QuoteMessage::class);
    }

    public function attachments()
    {
        return $this->hasMany(QuoteAttachment::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(QuoteActivityLog::class);
    }

    public function assignedStaff()
    {
        return $this->belongsTo(StaffMember::class, 'assigned_staff_id');
    }

    public function staffAssignments()
    {
        return $this->belongsToMany(StaffMember::class, 'quote_staff_assignments', 'quote_id', 'staff_member_id')
            ->withPivot('assigned_at')
            ->withTimestamps();
    }
}