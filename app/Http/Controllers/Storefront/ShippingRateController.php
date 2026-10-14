<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\ShopifyGraphQLException;
use App\Http\Controllers\Controller;
use App\Services\StorefrontShippingRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShippingRateController extends Controller
{
    /**
     * POST /api/proxy/shipping-rates   (storefront: /apps/quote-app/shipping-rates)
     *
     * Items + address par Shopify na eligible shipping rates. Hamesha 200 aape chhe;
     * rates na male to `reason` ma karan hoy chhe: no_address | no_items | no_rates | shopify_error
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.shopify_variant_id' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'address' => ['required', 'array'],
            'address.country' => ['nullable', 'string', 'max:100'],
            'address.country_code' => ['nullable', 'string', 'max:10'],
            'address.state' => ['nullable', 'string', 'max:100'],
            'address.state_code' => ['nullable', 'string', 'max:10'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.zip' => ['nullable', 'string', 'max:20'],
            'address.address' => ['nullable', 'string', 'max:255'],
        ]);

        $shop = $request->attributes->get('shop');

        try {
            $result = StorefrontShippingRateService::for($shop)->rates($data['items'], $data['address']);
        } catch (ShopifyGraphQLException $e) {
            Log::warning('Storefront shipping rates lookup failed', [
                'shop' => $shop->shop,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['rates' => [], 'reason' => 'shopify_error'],
            ]);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }
}
