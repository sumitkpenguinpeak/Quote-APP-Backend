<?php

use App\Models\ShopSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Default shop_settings param (prefix, suffix, quoteFormat, invoiceFormat, button appearance) ni entry:
 *  - jya shop_settings row j nathi tya navi row banave chhe
 *  - khali extra_settings hoy tya badha default
 *  - configured rows ma fakt missing keys umere chhe (merchant ni value overwrite nahi, prefix/suffix chhedata nathi)
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = ShopSetting::DEFAULT_EXTRA_SETTINGS;
        $numberingKeys = array_flip(ShopSetting::NUMBERING_KEYS);

        foreach (DB::table('shops')->select('id')->orderBy('id')->cursor() as $shop) {
            $row = DB::table('shop_settings')->where('shop_id', $shop->id)->first();

            if (! $row) {
                DB::table('shop_settings')->insert([
                    'shop_id' => $shop->id,
                    'extra_settings' => json_encode($defaults),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                continue;
            }

            $current = json_decode($row->extra_settings ?? '', true) ?: [];
            $base = empty($current) ? $defaults : array_diff_key($defaults, $numberingKeys);
            $missing = array_diff_key($base, $current);

            if (! empty($missing)) {
                DB::table('shop_settings')->where('id', $row->id)->update([
                    'extra_settings' => json_encode(array_merge($current, $missing)),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data backfill hoy che; revert ma kai delete nathi karta (merchant data na jai).
    }
};