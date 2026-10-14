<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyStorefrontService
{
    protected string $endpoint;
    protected string $token;

    public function query(
        string $query,
        array $variables = [],
        array $store = []
    ) {
        $shop = $store['shop'];
        $token = config('services.shopify.merchant_token');

        $version = '2026-07';

        $endpoint = "https://{$shop}/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Shopify-Storefront-Access-Token' => $token,
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            throw new \Exception(
                'Shopify API Error: ' . $response->body()
            );
        }

        $json = $response->json();

        if (isset($json['errors'])) {
            throw new \Exception(
                'Shopify GraphQL Error: ' .
                json_encode($json['errors'])
            );
        }

        return $json['data'] ?? null;
    }
}