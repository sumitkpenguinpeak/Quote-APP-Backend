<?php

namespace App\Services;

use App\Exceptions\ShopifyGraphQLException;
use App\Models\Shop;
use App\Support\ShopifyAddress;

/**
 * Storefront (Request a Quote page) mate Shopify shipping rates.
 * Quote haju bani nathi, etle items (variant + qty) ane customer address par draftOrderCalculate
 * chalavine store ni Shipping settings pramane eligible rates lave chhe.
 * Scope: write_draft_orders.
 */
class StorefrontShippingRateService
{
    public function __construct(private Shop $shop)
    {
    }

    public static function for(Shop $shop): self
    {
        return new self($shop);
    }

    /**
     * @param  array<int, array{shopify_variant_id?: ?string, quantity?: int}>  $items
     * @param  array<string, mixed>  $address  country, country_code, state, state_code, city, zip, address
     * @return array{rates: array<int, array{handle: string, title: string, price: float, currency: string}>, reason: ?string}
     *         reason: null | no_address | no_items | no_rates
     *
     * @throws ShopifyGraphQLException
     */
    public function rates(array $items, array $address): array
    {
        $shippingAddress = ShopifyAddress::fromArray($address);
        if (! $shippingAddress || blank($shippingAddress['zip'] ?? null)) {
            return ['rates' => [], 'reason' => 'no_address'];
        }

        // Variant vagar na (custom) items ni weight/price khabar nathi -> rate calculation ma na levay
        $lineItems = collect($items)
            ->filter(fn ($i) => ! empty($i['shopify_variant_id']))
            ->map(fn ($i) => [
                'variantId' => 'gid://shopify/ProductVariant/' . preg_replace('/\D/', '', (string) $i['shopify_variant_id']),
                'quantity' => max(1, (int) ($i['quantity'] ?? 1)),
            ])
            ->values()
            ->all();

        if (empty($lineItems)) {
            return ['rates' => [], 'reason' => 'no_items'];
        }

        try {
            $rates = $this->calculate($lineItems, $shippingAddress);
        } catch (ShopifyGraphQLException $e) {
            // Province code khotho hoy to province vagar ek var pachho try karo
            if (! isset($shippingAddress['provinceCode'])) {
                throw $e;
            }
            unset($shippingAddress['provinceCode']);
            $rates = $this->calculate($lineItems, $shippingAddress);
        }

        return ['rates' => $rates, 'reason' => $rates ? null : 'no_rates'];
    }

    private function calculate(array $lineItems, array $shippingAddress): array
    {
        $mutation = <<<'GRAPHQL'
            mutation draftOrderCalculate($input: DraftOrderInput!) {
                draftOrderCalculate(input: $input) {
                    calculatedDraftOrder {
                        availableShippingRates {
                            handle
                            title
                            price {
                                amount
                                currencyCode
                            }
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $graphql = ShopifyGraphQLService::for($this->shop);

        $data = $graphql->query($mutation, [
            'input' => [
                'lineItems' => $lineItems,
                'shippingAddress' => $shippingAddress,
            ],
        ]);

        $graphql->assertNoUserErrors(
            $data['draftOrderCalculate']['userErrors'] ?? [],
            'Shipping rate calculation'
        );

        return collect($data['draftOrderCalculate']['calculatedDraftOrder']['availableShippingRates'] ?? [])
            ->map(fn ($rate) => [
                'handle' => $rate['handle'],
                'title' => $rate['title'],
                'price' => (float) ($rate['price']['amount'] ?? 0),
                'currency' => $rate['price']['currencyCode'] ?? ($this->shop->currency ?: 'USD'),
            ])
            ->values()
            ->all();
    }
}
