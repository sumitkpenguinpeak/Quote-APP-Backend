<?php

namespace App\Services;

use App\Exceptions\ShopifyGraphQLException;
use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyGraphQLService
{
    private Shop $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * Convenience factory: ShopifyGraphQLService::for($shop)->query(...)
     */
    public static function for(Shop $shop): self
    {
        return new self($shop);
    }

    /**
     * Runs a raw GraphQL query/mutation against this shop's Admin API.
     *
     * @param  string  $query      The GraphQL document (query or mutation).
     * @param  array   $variables  GraphQL variables.
     * @return array               The `data` portion of the response.
     *
     * @throws ShopifyGraphQLException on transport failure or GraphQL "errors".
     */
    public function query(string $query, array $variables = []): array
    {
        // shops table ma access_token plain store thay chhe (AuthController), etle decrypt() nahi.
        // Expiring token hoy to pehla renew thay chhe.
        $tokens = app(ShopifyTokenService::class);
        $accessToken = $tokens->ensureFresh($this->shop);

        $response = $this->send($accessToken, $query, $variables);

        // 401 = token reject thayo -> ek vaar force refresh karine fari try
        if ($response->status() === 401 && $this->shop->refresh_token) {
            $accessToken = $tokens->ensureFresh($this->shop, $accessToken);
            $response = $this->send($accessToken, $query, $variables);
        }

        if ($response->failed()) {
            Log::error('Shopify GraphQL transport error', [
                'shop' => $this->shop->shop,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new ShopifyGraphQLException(
                "Shopify GraphQL request failed with HTTP {$response->status()}.",
                ['http_status' => $response->status(), 'body' => $response->json()]
            );
        }

        $payload = $response->json();

        if (! empty($payload['errors'])) {
            Log::error('Shopify GraphQL returned errors', [
                'shop' => $this->shop->shop,
                'errors' => $payload['errors'],
            ]);

            throw new ShopifyGraphQLException(
                $payload['errors'][0]['message'] ?? 'Unknown Shopify GraphQL error.',
                $payload['errors']
            );
        }

        // userErrors is Shopify's per-mutation validation error convention -
        // surfaced but not thrown here, since callers often want to show
        // these directly to the merchant rather than a hard failure.
        return $payload['data'] ?? [];
    }

    private function send(string $accessToken, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        // IMPORTANT: variables khali hoy to key j moklvi nahi.
        // PHP no khali array JSON ma `[]` bane chhe, ane Shopify "Invalid variables parameter."
        // error aape chhe (variables hamesha JSON object `{}` hovu joiye).
        $body = ['query' => $query];
        if (! empty($variables)) {
            $body['variables'] = $variables;
        }

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])
            ->timeout(20)
            ->post("https://{$this->shop->shop}/admin/api/" . config('shopify.api_version') . '/graphql.json', $body);
    }

    /**
     * Checks a mutation's payload for Shopify's `userErrors` field and
     * throws if any are present. Call this after mutations that return one.
     *
     * @param  array  $userErrors  e.g. $data['draftOrderCreate']['userErrors']
     */
    public function assertNoUserErrors(array $userErrors, string $context = 'Shopify mutation'): void
    {
        if (! empty($userErrors)) {
            $messages = collect($userErrors)->pluck('message')->implode(' | ');

            throw new ShopifyGraphQLException("{$context} failed: {$messages}", $userErrors);
        }
    }
}