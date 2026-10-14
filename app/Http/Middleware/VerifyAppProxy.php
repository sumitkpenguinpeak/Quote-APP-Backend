<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates public storefront requests that arrive through the Shopify
 * App Proxy (https://{shop}/apps/quote-app/... -> /api/proxy/...).
 *
 * Shopify appends `shop`, `timestamp` and `signature` to the query string.
 * The signature is an HMAC-SHA256 (hex) of the sorted `key=value` pairs
 * (no separator) with the app secret. The shop is taken from the signed query.
 */
class VerifyAppProxy
{
    private const MAX_AGE_SECONDS = 90;

    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query();
        $signature = $query['signature'] ?? null;

        if (! is_string($signature) || $signature === '' || ! $this->validSignature($query, $signature)) {
            return $this->forbidden('Invalid proxy signature.');
        }

        $timestamp = (int) ($query['timestamp'] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_AGE_SECONDS) {
            return $this->forbidden('Expired proxy request.');
        }

        $shopDomain = strtolower(trim((string) ($query['shop'] ?? '')));
        $shop = Shop::where('shop', $shopDomain)->first();

        if (! $shop || ! $shop->is_active) {
            return response()->json(['success' => false, 'message' => 'Shop is not installed.'], 404);
        }

        $request->attributes->set('shop', $shop);
        // Controllers read `$request->shop`; force the signed value.
        $request->merge(['shop' => $shopDomain]);

        return $next($request);
    }

    protected function validSignature(array $query, string $signature): bool
    {
        unset($query['signature']);
        ksort($query);

        $message = '';
        foreach ($query as $key => $value) {
            $message .= $key . '=' . (is_array($value) ? implode(',', $value) : $value);
        }

        $computed = hash_hmac('sha256', $message, (string) config('shopify.api_secret'));

        return hash_equals($computed, $signature);
    }

    protected function forbidden(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
