<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Class ShopifyService
 *
 * Encapsulates all direct communication with Shopify: OAuth URL building,
 * access token exchange, HMAC / webhook signature verification and thin
 * wrappers around the Admin GraphQL API.
 */
class ShopifyService
{
    protected Client $client;
    protected string $apiKey;
    protected string $apiSecret;
    protected string $appUrl;
    protected string $apiVersion;
    protected string $scopes;

    public function __construct()
    {
        $this->apiKey = (string) config('shopify.api_key');
        $this->apiSecret = (string) config('shopify.api_secret');
        $this->appUrl = rtrim((string) config('shopify.app_url'), '/');
        $this->apiVersion = (string) config('shopify.api_version');
        $this->scopes = (string) config('shopify.scopes');

        $this->client = new Client([
            'timeout' => 15,
        ]);
    }

    /**
     * Embedded app URL inside the Shopify admin:
     * https://admin.shopify.com/store/{store}/apps/{app-handle}{path}
     */
    public function adminAppUrl(string $shopDomain, string $path = ''): string
    {
        $handle = config('shopify.page.app_handle');

        if (! $handle) {
            $frontend = rtrim((string) config('shopify.frontend_url'), '/');

            return "{$frontend}{$path}?shop={$shopDomain}";
        }

        $store = preg_replace('/\.myshopify\.com$/', '', $shopDomain);

        return "https://admin.shopify.com/store/{$store}/apps/{$handle}{$path}";
    }

    /**
     * Validate that a shop domain looks like a genuine *.myshopify.com domain.
     */
    public function isValidShopDomain(string $shop): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop);
    }

    /**
     * Build the URL Shopify should redirect the merchant to in order to
     * approve the app's requested scopes.
     */
    public function buildAuthorizationUrl(string $shop, string $state): string
    {
        $redirectUri = $this->appUrl . '/api/auth/callback';

        $query = http_build_query([
            'client_id' => $this->apiKey,
            'scope' => $this->scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'expiring' => 1
        ]);

        return "https://{$shop}/admin/oauth/authorize?{$query}";
    }

    /**
     * Verify the HMAC signature Shopify attaches to every OAuth / app-proxy
     * request that originates from Shopify (query-string based).
     */
    public function verifyHmac(array $params): bool
    {
        if (! isset($params['hmac'])) {
            return false;
        }

        $hmac = $params['hmac'];
        $data = $params;
        unset($data['hmac'], $data['signature']);

        ksort($data);

        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = "{$key}=" . (is_array($value) ? implode(',', $value) : $value);
        }

        $computed = hash_hmac('sha256', implode('&', $pairs), $this->apiSecret);

        return hash_equals($computed, (string) $hmac);
    }

    /**
     * Verify the HMAC signature Shopify sends on every webhook request
     * (header based, computed over the raw request body).
     */
    public function verifyWebhookHmac(string $rawBody, ?string $hmacHeader): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $this->apiSecret, true));

        return hash_equals($computed, $hmacHeader);
    }

    /**
     * Exchange the temporary OAuth code for a permanent access token.
     *
     * @throws GuzzleException
     */
    public function exchangeAccessToken(string $shop, string $code): array
    {
        $response = $this->client->post("https://{$shop}/admin/oauth/access_token", [
            'json' => [
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
                'code' => $code,
                'expiring' => 1
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Expiring offline token refresh: purana refresh_token thi navo access_token +
     * navo refresh_token male chhe (refresh_token ek j vaar vapray chhe).
     *
     * @throws GuzzleException
     */
    public function refreshAccessToken(string $shop, string $refreshToken): array
    {
        $response = $this->client->post("https://{$shop}/admin/oauth/access_token", [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'client_id' => $this->apiKey,
                'client_secret' => $this->apiSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Fetch basic shop information (name, plan, email) using the Admin
     * GraphQL API. Requires a valid access token.
     */
    public function getShopDetails(string $shop, string $accessToken): array
    {
        $query = <<<'GRAPHQL'
        query {
            shop {
                name
                email
                myshopifyDomain
                plan {
                    displayName
                }
            }
        }
        GRAPHQL;

        $result = $this->graphqlRequest($shop, $accessToken, $query);

        return $result['data']['shop'] ?? [];
    }

    /**
     * Register the webhooks defined in config/shopify.php for the given shop.
     */
    public function registerWebhooks(string $shop, string $accessToken): void
    {
        foreach (config('shopify.webhooks', []) as $webhook) {
            $mutation = <<<'GRAPHQL'
            mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
                webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                    webhookSubscription { id }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $variables = [
                'topic' => strtoupper(str_replace(['/', '.'], '_', $webhook['topic'])),
                'webhookSubscription' => [
                    'callbackUrl' => $this->appUrl . $webhook['path'],
                    'format' => 'JSON',
                ],
            ];

            try {
                $this->graphqlRequest($shop, $accessToken, $mutation, $variables);
            } catch (GuzzleException $exception) {
                Log::warning('Failed to register Shopify webhook', [
                    'shop' => $shop,
                    'topic' => $webhook['topic'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Perform an authenticated Admin GraphQL API request.
     *
     * @throws GuzzleException
     */
    public function graphqlRequest(string $shop, string $accessToken, string $query, array $variables = []): array
    {
        $response = $this->client->post("https://{$shop}/admin/api/{$this->apiVersion}/graphql.json", [
            'headers' => [
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => array_filter([
                'query' => $query,
                'variables' => $variables ?: null,
            ]),
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Generate a cryptographically secure random state value used to
     * protect the OAuth flow against CSRF.
     */
    public function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }
}
