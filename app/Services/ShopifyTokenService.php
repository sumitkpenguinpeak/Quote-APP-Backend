<?php

namespace App\Services;

use App\Exceptions\ShopifyGraphQLException;
use App\Models\Shop;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shopify expiring offline access token (1 kalak) ne refresh_token thi aapoaap renew kare chhe.
 * Badhe Admin API call karti vakhte $shop->access_token ni jagya ahiya thi token lo.
 */
class ShopifyTokenService
{
    /** Expire thava na 5 minute pehla j renew kari do */
    private const REFRESH_BUFFER_SECONDS = 300;

    public function __construct(private ShopifyService $shopifyService)
    {
    }

    /**
     * Valid access token aape chhe; jarur hoy to pehla refresh kare chhe.
     *
     * @param  string|null  $rejectedToken  Shopify e je token 401 sathe reject karyo hoy (force refresh mate)
     *
     * @throws ShopifyGraphQLException
     */
    public function ensureFresh(Shop $shop, ?string $rejectedToken = null): string
    {
        // refresh_token nathi (juno non-expiring token) -> refresh nahi thai shake, jem chhe tem j aapo
        if (! $shop->refresh_token) {
            return (string) $shop->access_token;
        }

        if ($rejectedToken === null && ! $this->isExpiring($shop)) {
            return (string) $shop->access_token;
        }

        // refresh_token ek-vaar-vapray chhe, etle bey requests ek sathe refresh na kare e mate lock
        return Cache::lock("shopify-token-refresh:{$shop->id}", 30)->block(15, function () use ($shop, $rejectedToken) {
            $shop->refresh(); // biji request e pehla j refresh kari didhu hoy to e j vaparo

            if ($rejectedToken === null ? ! $this->isExpiring($shop) : $shop->access_token !== $rejectedToken) {
                return (string) $shop->access_token;
            }

            return $this->refresh($shop);
        });
    }

    private function isExpiring(Shop $shop): bool
    {
        return $shop->access_token_expires_at !== null
            && $shop->access_token_expires_at->lte(now()->addSeconds(self::REFRESH_BUFFER_SECONDS));
    }

    private function refresh(Shop $shop): string
    {
        if ($shop->refresh_token_expires_at && $shop->refresh_token_expires_at->isPast()) {
            throw new ShopifyGraphQLException('Shopify access has expired. Please reinstall the app on the store.');
        }

        try {
            $data = $this->shopifyService->refreshAccessToken($shop->shop, (string) $shop->refresh_token);
        } catch (GuzzleException $e) {
            Log::error('Shopify token refresh failed', ['shop' => $shop->shop, 'error' => $e->getMessage()]);

            throw new ShopifyGraphQLException(
                'Shopify token refresh failed. Please reinstall the app on the store.',
                ['reason' => $e->getMessage()]
            );
        }

        $accessToken = $data['access_token'] ?? null;

        if (! $accessToken) {
            throw new ShopifyGraphQLException('Shopify did not return a new access token.', $data);
        }

        $shop->update([
            'access_token' => $accessToken,
            'refresh_token' => $data['refresh_token'] ?? $shop->refresh_token,
            'access_token_expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            'refresh_token_expires_at' => isset($data['refresh_token_expires_in']) ? now()->addSeconds((int) $data['refresh_token_expires_in']) : null,
        ]);

        return $accessToken;
    }
}
