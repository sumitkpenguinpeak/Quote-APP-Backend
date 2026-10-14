<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\ShopifySessionTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class VerifyShop
 *
 * Authenticates embedded-admin API calls with the App Bridge session token
 * (JWT in `Authorization: Bearer <token>`). The shop is taken ONLY from the
 * verified token's `dest` claim - the `shop` query param / X-Shopify-Shop-Domain
 * header are never trusted for identity. If a client still sends a `shop`
 * value that disagrees with the token, the request is rejected.
 */
class VerifyShop
{
    public function __construct(protected ShopifySessionTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $request->bearerToken();

        if (! $jwt) {
            return $this->unauthorized('Missing session token.');
        }

        $verified = $this->tokens->verify($jwt);

        if (! $verified) {
            return $this->unauthorized('Invalid or expired session token.');
        }

        $shopDomain = $verified['shop'];

        // Defence in depth: a client-supplied shop must match the token's shop.
        $claimed = $request->query('shop') ?? $request->input('shop') ?? $request->header('X-Shopify-Shop-Domain');
        if ($claimed && strtolower(trim((string) $claimed)) !== $shopDomain) {
            return $this->unauthorized('Shop does not match session token.');
        }

        $shop = Shop::where('shop', $shopDomain)->first();

        if (! $shop || ! $shop->is_active || ! $shop->access_token) {
            return response()->json([
                'success' => false,
                'code' => 'not_installed',
                'message' => 'Shop is not installed. Please reinstall the app.',
            ], 401);
        }

        $request->attributes->set('shop', $shop);

        // Controllers (e.g. `$request->shop`) read the shop from the request; make
        // sure that is always the verified one.
        $request->merge(['shop' => $shopDomain]);

        return $next($request);
    }

    protected function unauthorized(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 401);
    }
}
