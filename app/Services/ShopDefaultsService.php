<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Support\DefaultTemplates;

/**
 * Navi shop mate default data DB ma banave chhe:
 *   - shop_settings.extra_settings  -> default settings + quote_pdf_template + invoice_pdf_template
 *   - email_templates               -> badha email types na default subject/body
 *
 * Idempotent chhe: merchant e je template edit kari hoy te kyarey overwrite thatu nathi
 * (reinstall par pan safe). Fakt missing hoy te j umray chhe.
 */
class ShopDefaultsService
{
    public function seed(Shop|int $shop): void
    {
        $this->seedSettings($shop);
        $this->seedEmailTemplates($shop);
    }

    /** Settings row + Quote PDF / Invoice PDF default templates (missing keys j). */
    public function seedSettings(Shop|int $shop): ShopSetting
    {
        return ShopSetting::forShop($shop);
    }

    /** Email templates: je type no row nathi tya default row banave chhe. */
    public function seedEmailTemplates(Shop|int $shop): void
    {
        $shopId = $shop instanceof Shop ? $shop->id : $shop;

        foreach (DefaultTemplates::emails() as $type => [$subject, $body]) {
            EmailTemplate::firstOrCreate(
                ['shop_id' => $shopId, 'type' => $type],
                ['subject' => $subject, 'body_html' => $body, 'is_active' => true]
            );
        }
    }
}
