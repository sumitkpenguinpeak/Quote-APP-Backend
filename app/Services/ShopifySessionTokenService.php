<?php

namespace App\Services;

/**
 * Verifies the App Bridge session token (a Shopify-signed HS256 JWT).
 *
 * Checks: algorithm, signature (SHOPIFY_API_SECRET), exp / nbf (small clock
 * leeway), aud == SHOPIFY_API_KEY, and that `iss` and `dest` are the same
 * valid *.myshopify.com shop. Returns the shop domain taken from `dest`,
 * never from anything the client can freely choose.
 */
class ShopifySessionTokenService
{
    private const LEEWAY_SECONDS = 10;

    /**
     * @return array{shop: string, claims: array}|null  null when the token is invalid
     */
    public function verify(?string $jwt): ?array
    {
        $apiKey = (string) config('shopify.api_key');
        $secret = (string) config('shopify.api_secret');

        if (! $jwt || $apiKey === '' || $secret === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$encHeader, $encPayload, $encSignature] = $parts;

        $header = json_decode((string) $this->base64UrlDecode($encHeader), true);
        $claims = json_decode((string) $this->base64UrlDecode($encPayload), true);
        $signature = $this->base64UrlDecode($encSignature);

        if (! is_array($header) || ! is_array($claims) || $signature === false) {
            return null;
        }

        // Only HS256 - never trust the token's own idea of the algorithm ("none" etc.).
        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', "{$encHeader}.{$encPayload}", $secret, true);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $now = time();

        if (! isset($claims['exp']) || (int) $claims['exp'] < ($now - self::LEEWAY_SECONDS)) {
            return null;
        }

        if (isset($claims['nbf']) && (int) $claims['nbf'] > ($now + self::LEEWAY_SECONDS)) {
            return null;
        }

        if (($claims['aud'] ?? null) !== $apiKey) {
            return null;
        }

        $destHost = $this->hostOf($claims['dest'] ?? null);
        $issHost = $this->hostOf($claims['iss'] ?? null);

        if (! $destHost || ! $issHost || $destHost !== $issHost) {
            return null;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $destHost)) {
            return null;
        }

        return ['shop' => $destHost, 'claims' => $claims];
    }

    private function hostOf(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
