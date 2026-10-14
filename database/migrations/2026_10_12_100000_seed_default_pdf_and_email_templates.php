<?php

use App\Models\Shop;
use App\Services\ShopDefaultsService;
use Illuminate\Database\Migrations\Migration;

/**
 * Already install thayela shops mate default templates DB ma umero:
 *  - shop_settings.extra_settings.quote_pdf_template / invoice_pdf_template (jya missing hoy tya j)
 *  - email_templates: je type no row nathi tya default subject/body
 *
 * Merchant e je template edit kari hoy te overwrite thatu nathi.
 * Navi installation ma aa j kaam AuthController::callback -> ShopDefaultsService::seed() kare chhe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(ShopDefaultsService::class);

        Shop::query()->select('id')->orderBy('id')->each(function (Shop $shop) use ($service) {
            $service->seed($shop->id);
        });
    }

    public function down(): void
    {
        // Data backfill hoy che; revert ma kai delete nathi karta (merchant data na jai).
    }
};
