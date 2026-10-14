<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateShopSettingRequest;
use App\Models\ShopSetting;
use Illuminate\Http\Request;

class ShopSettingController extends Controller
{
    /**
     * GET /api/settings
     */
    public function show(UpdateShopSettingRequest $request)
    {
        $shop = $request->attributes->get('shop');
        $settings = ShopSetting::forShop($shop);
        return response()->json(['data' => $settings]);
    }

    public function collections(UpdateShopSettingRequest $request)
    {
        $shop = $request->attributes->get('shop');
        $settings = ShopSetting::forShop($shop);
        return response()->json([
            'data' => [
                'settings' => $settings->extra_settings,
            ],
        ]);
    }

    /**
     * PUT /api/settings
     */
    // public function update(UpdateShopSettingRequest $request)
    // {
    //     \DB::enableQueryLog(); 
    //     $shop = $request->attributes->get('shop');
    //     $settings = ShopSetting::forShop($shop);
    //     echo "<pre>";print_r($settings);die;
    //     $settings->update($request->validated());
    //     $lastQuery = collect(\DB::getQueryLog())->last();
    //     \Log::info('Last Query:', $lastQuery);
    //     dd($lastQuery);


    //     return response()->json(['data' => $settings->fresh()]);
    // }
    // public function update(UpdateShopSettingRequest $request)
    // {
    //     $shop = $request->attributes->get('shop');
    //     $settings = ShopSetting::forShop($shop);

    //     $validated = $request->validated();

    //     // Table na actual columns levo (schema thi dynamically)
    //     $tableColumns = \Schema::getColumnListing($settings->getTable());

    //     // Je fields table ma column tarike chhe e normal data
    //     $normalData = collect($validated)->only($tableColumns)->toArray();

    //     // Baki na badha fields (jo koi column nathi) extra_settings ma jashe
    //     $extraData = collect($validated)->except($tableColumns)->toArray();

    //     echo "<pre>";print_r($extraData);die;

    //     if (!empty($extraData)) {
    //         $normalData['extra_settings'] = array_merge(
    //             $settings->extra_settings ?? [],
    //             $extraData
    //         );
    //     }

    //     $settings->update($normalData);

    //     return response()->json(['data' => $settings->fresh()]);
    // }
    public function update(UpdateShopSettingRequest $request)
    {
        $shop = $request->attributes->get('shop');
        $settings = ShopSetting::forShop($shop);

        $validated = $request->validated();

        // Editing the PDF templates is a Pro feature.
        $templateKeys = ['quote_pdf_template', 'invoice_pdf_template'];
        if (
            ! $shop->canUse('templates')
            && array_intersect($templateKeys, array_keys($validated['extra_settings'] ?? []))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Modifying PDF templates is available on the Pro plan. Please upgrade your plan to use it.',
            ], 403);
        }

        if (array_key_exists('extra_settings', $validated)) {
            $validated['extra_settings'] = array_merge(
                $settings->extra_settings ?? [],
                $validated['extra_settings']
            );
        }

        $settings->update($validated);

        return response()->json(['data' => $settings->fresh()]);
    }
}