<?php

namespace App\Models;

use App\Support\DefaultTemplates;
use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    protected $fillable = [
        'shop_id',          // firstOrCreate(['shop_id' => ...]) mate joiye
        'extra_settings'
    ];
    // protected $fillable = [
    //     'shop_id', 'button_text', 'button_color', 'button_text_color', 'button_position',
    //     'show_on_product_page', 'show_on_collection_page', 'show_on_cart_page',
    //     'hide_price_globally', 'hide_add_to_cart_globally', 'hidden_price_replacement_text',
    //     'allow_multiple_products_per_quote', 'allow_customer_suggest_price', 'allow_quantity_edit',
    //     'quote_expiry_days', 'auto_create_draft_order_on_approve', 'notification_email',
    //     'notify_merchant_on_new_quote', 'notify_customer_on_status_change', 'extra_settings',
    // ];

    protected $casts = [
        // 'show_on_product_page' => 'boolean',
        // 'show_on_collection_page' => 'boolean',
        // 'show_on_cart_page' => 'boolean',
        // 'hide_price_globally' => 'boolean',
        // 'hide_add_to_cart_globally' => 'boolean',
        // 'allow_multiple_products_per_quote' => 'boolean',
        // 'allow_customer_suggest_price' => 'boolean',
        // 'allow_quantity_edit' => 'boolean',
        // 'auto_create_draft_order_on_approve' => 'boolean',
        // 'notify_merchant_on_new_quote' => 'boolean',
        // 'notify_customer_on_status_change' => 'boolean',
        'extra_settings' => 'array',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Navi shop_settings row ma by default aa badha param save thay chhe
     * (extra_settings JSON). Settings > Formatting standards ane Button appearance na defaults.
     */
    public const DEFAULT_EXTRA_SETTINGS = [
        // Formatting standards
        'prefix' => 'Q-',
        'suffix' => '-SUF',
        'quoteFormat' => 'IW{{create_date}}-{{quote_id}}',
        'invoiceFormat' => 'IW{{order_date}}-{{quote_id}}',

        // Button appearance
        'buttonText' => 'Request a Quote',
        'buttonColor' => '000000',
        'textColor' => 'FFFFFF',
        'cornerRadius' => 8,
        'showOnProduct' => true,
        'showOnCollection' => true,
        'showOnCart' => false,
        'hidePriceGlobally' => false,
        'hideAddToCart' => false,
    ];

    /**
     * Juni (already configured) rows ma aa keys backfill nathi thata: prefix + suffix badalva thi
     * quote numbering badlay (Quote::nextQuoteNumber), je live shops mate surprise kahevay.
     */
    public const NUMBERING_KEYS = ['prefix', 'suffix'];

    /**
     * DEFAULT_EXTRA_SETTINGS + default Quote PDF / Invoice PDF templates.
     * (Template ma array banavva function joiye, etle const ma nathi.)
     */
    public static function defaultExtraSettings(): array
    {
        return array_merge(static::DEFAULT_EXTRA_SETTINGS, [
            'quote_pdf_template' => DefaultTemplates::quotePdf(),
            'invoice_pdf_template' => DefaultTemplates::invoicePdf(),
        ]);
    }

    /**
     * Shop ni settings row lavo; na hoy to default param sathe banavo.
     *
     *  - Navi row           -> badha default DB ma save thay chhe.
     *  - Khali (never configured) row -> badha default.
     *  - Configured row     -> fakt je keys missing hoy te umray chhe (merchant ni value kyarey overwrite nahi);
     *                          prefix/suffix chhedata nathi.
     */
    public static function forShop(Shop|int $shop): self
    {
        $shopId = $shop instanceof Shop ? $shop->id : $shop;

        $defaults = static::defaultExtraSettings();

        $settings = static::firstOrCreate(
            ['shop_id' => $shopId],
            ['extra_settings' => $defaults]
        );

        if ($settings->wasRecentlyCreated) {
            return $settings;
        }

        $current = $settings->extra_settings ?? [];
        $base = empty($current)
            ? $defaults
            : array_diff_key($defaults, array_flip(static::NUMBERING_KEYS));

        $missing = array_diff_key($base, $current);
        if (! empty($missing)) {
            $settings->extra_settings = array_merge($current, $missing);
            $settings->save();
        }

        return $settings;
    }
}