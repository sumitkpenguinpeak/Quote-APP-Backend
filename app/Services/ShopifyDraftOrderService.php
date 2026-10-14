<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Support\ShopifyAddress;
use Illuminate\Support\Facades\DB;

class ShopifyDraftOrderService
{
    private ShopifyGraphQLService $graphql;

    public function __construct(private Quote $quote)
    {
        $this->graphql = ShopifyGraphQLService::for($quote->shop);
    }

    public static function for(Quote $quote): self
    {
        return new self($quote);
    }

    /**
     * Shopify store ni Shipping settings pramane aa quote mate ELIGIBLE shipping rates.
     * draftOrderCalculate -> availableShippingRates (customer na shipping address ane items par aadharit).
     * Scope: write_draft_orders (je draft order banava mate pehla thi joiye j chhe).
     *
     * @return array{rates: array<int, array{handle: string, title: string, price: float, currency: string}>, reason: ?string}
     *         reason: null | no_address | no_items | no_rates
     */
    public function availableShippingRates(): array
    {
        $quote = $this->quote->load(['items', 'customer']);
        $currency = $quote->currency ?: 'USD';

        // Rates customer na address par aadharit hoy chhe, etle address (country sathe) joiye
        $address = ShopifyAddress::fromCustomer($quote->customer);
        if (! $address) {
            return ['rates' => [], 'reason' => 'no_address'];
        }

        $lineItems = $this->buildLineItems($quote, $currency);
        if (empty($lineItems)) {
            return ['rates' => [], 'reason' => 'no_items'];
        }

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

        $data = $this->graphql->query($mutation, [
            'input' => [
                'lineItems' => $lineItems,
                'email' => $quote->customer?->email,
                'shippingAddress' => $address,
            ],
        ]);

        $this->graphql->assertNoUserErrors(
            $data['draftOrderCalculate']['userErrors'] ?? [],
            'Shipping rate calculation'
        );

        $rates = collect($data['draftOrderCalculate']['calculatedDraftOrder']['availableShippingRates'] ?? [])
            ->map(fn ($rate) => [
                'handle' => $rate['handle'],
                'title' => $rate['title'],
                'price' => (float) ($rate['price']['amount'] ?? 0),
                'currency' => $rate['price']['currencyCode'] ?? $currency,
            ])
            ->values()
            ->all();

        return ['rates' => $rates, 'reason' => $rates ? null : 'no_rates'];
    }

    /**
     * Quote items ne Shopify DraftOrder lineItems ma convert kare chhe
     * (draft order create ane shipping rate calculate bey mate same logic).
     */
    private function buildLineItems(Quote $quote, string $currency): array
    {
        return $quote->items->map(function ($item) use ($currency) {
            $agreedPrice = $item->final_price ?? $item->merchant_offered_price ?? $item->customer_requested_price ?? $item->original_price;
            // Product wise discount lagavo (line_total ma discount pehla thi included chhe)
            if ($item->discount_value && $item->quantity > 0 && $item->line_total !== null) {
                $agreedPrice = (float) $item->line_total / $item->quantity;
            }
            $price = ['amount' => number_format((float) $agreedPrice, 2, '.', ''), 'currencyCode' => $currency];

            // Item properties -> Shopify line item customAttributes
            $customAttributes = collect($item->properties ?? [])
                ->filter(fn ($p) => !empty($p['name']))
                ->map(fn ($p) => ['key' => $p['name'], 'value' => (string) ($p['value'] ?? '')])
                ->values()->all();

            // Variant hoy to: variant ne link rakhine price override (inventory + product link rahe).
            // NOTE: variantId vali line par originalUnitPrice Shopify ignore kare chhe (deprecated), etle priceOverride.
            if ($item->shopify_variant_id) {
                return [
                    'quantity' => $item->quantity,
                    'variantId' => "gid://shopify/ProductVariant/{$item->shopify_variant_id}",
                    'priceOverride' => $price,
                    ...($customAttributes ? ['customAttributes' => $customAttributes] : []),
                ];
            }

            // Variant nathi (delete thayo) -> custom line item
            return [
                'quantity' => $item->quantity,
                'title' => $item->product_title,
                'originalUnitPriceWithCurrency' => $price,
                ...($customAttributes ? ['customAttributes' => $customAttributes] : []),
            ];
        })->values()->all();
    }

    /**
     * Creates a Shopify Draft Order from this quote's line items (using the
     * agreed final_price / merchant_offered_price for each), then writes the
     * resulting draft order id + checkout url back onto the `quotes` row.
     */
    public function createFromQuote(): Quote
    {
        $quote = $this->quote->load(['items', 'customer']);
        $quote->recalculateTotals(); // discount / tax amounts taaji calculate

        $currency = $quote->currency ?: 'USD';

        $lineItems = $this->buildLineItems($quote, $currency);

        $mutation = <<<'GRAPHQL'
            mutation draftOrderCreate($input: DraftOrderInput!) {
                draftOrderCreate(input: $input) {
                    draftOrder {
                        id
                        name
                        invoiceUrl
                        totalPrice
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $input = [
            'lineItems' => $lineItems,
            'email' => $quote->customer?->email,
            'note' => "Converted from quote {$quote->quote_number}",
            'tags' => ['request-a-quote', $quote->quote_number],
        ];

        // ---- Customer address (shipping + billing) ----
        // Address hoy to draft order ma set thay, jethi Shopify shipping address par thi tax calculate kare.
        if ($address = ShopifyAddress::fromCustomer($quote->customer)) {
            $input['shippingAddress'] = $address;
            $input['billingAddress'] = $address;
        }

        // ---- Add discount popup ----
        if ((float) $quote->discount_amount > 0) {
            $isPercent = $quote->discount_type === 'percentage';
            // "Display discount reason on the draft order" tick hoy to j reason customer ne dekhay
            $showReason = (bool) $quote->discount_show_on_draft_order && filled($quote->discount_reason);

            $input['appliedDiscount'] = array_filter([
                'title' => $showReason ? $quote->discount_reason : 'Discount',
                'description' => $showReason ? $quote->discount_reason : null,
                'value' => $isPercent ? (float) $quote->discount_value : (float) $quote->discount_amount,
                'valueType' => $isPercent ? 'PERCENTAGE' : 'FIXED_AMOUNT',
            ], fn ($v) => $v !== null);
        }

        // ---- Add shipping popup (custom shipping rate) ----
        if ((float) $quote->shipping_price > 0) {
            $input['shippingLine'] = [
                'title' => $quote->shipping_name ?: 'Shipping',
                'price' => number_format((float) $quote->shipping_price, 2, '.', ''),
            ];
        }

        // ---- Tax ----
        // Shopify draft order ma custom tax amount/rate set karvano field nathi. Etle quote no custom tax
        // line item tarike nathi moklato; Shopify potani store tax settings (Settings > Taxes and duties)
        // + shipping address par thi tax calculate kare chhe ane real "Tax" row ma dekhade chhe.
        $input['taxExempt'] = false;

        $variables = ['input' => $input];

        $data = $this->graphql->query($mutation, $variables);

        $this->graphql->assertNoUserErrors(
            $data['draftOrderCreate']['userErrors'] ?? [],
            'Draft order creation'
        );

        $draftOrder = $data['draftOrderCreate']['draftOrder'];
        $numericId = $this->extractNumericId($draftOrder['id']);

        DB::transaction(function () use ($quote, $draftOrder, $numericId) {
            $quote->update([
                'shopify_draft_order_id' => $numericId,
                'shopify_draft_order_gid' => $draftOrder['id'],
                'checkout_url' => $draftOrder['invoiceUrl'],
                'status' => Quote::STATUS_CONVERTED,
                'converted_at' => now(),
            ]);

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'converted',
                'performed_by_type' => 'staff',
                'description' => "Converted to Shopify draft order {$draftOrder['name']}",
                'meta' => ['draft_order_id' => $numericId, 'draft_order_gid' => $draftOrder['id']],
            ]);
        });

        return $quote->fresh(['items', 'customer']);
    }

    /** Update an already-created Shopify draft order from the current quote. */
    public function updateExistingDraftOrder(): Quote
    {
        $quote = $this->quote->load(['items', 'customer']);
        if (! $quote->shopify_draft_order_gid) {
            throw new \RuntimeException('This quote does not have a Shopify draft order yet.');
        }
        $quote->recalculateTotals();
        $input = [
            'lineItems' => $this->buildLineItems($quote, $quote->currency ?: 'USD'),
            'email' => $quote->customer?->email,
            'note' => "Updated from quote {$quote->quote_number}",
        ];
        if ((float) $quote->discount_amount > 0) {
            $isPercent = $quote->discount_type === 'percentage';
            $input['appliedDiscount'] = [
                'title' => ($quote->discount_show_on_draft_order && filled($quote->discount_reason)) ? $quote->discount_reason : 'Discount',
                'value' => $isPercent ? (float) $quote->discount_value : (float) $quote->discount_amount,
                'valueType' => $isPercent ? 'PERCENTAGE' : 'FIXED_AMOUNT',
            ];
        }
        if ((float) $quote->shipping_price > 0) {
            $input['shippingLine'] = [
                'title' => $quote->shipping_name ?: 'Shipping',
                'price' => number_format((float) $quote->shipping_price, 2, '.', ''),
            ];
        }
        $mutation = <<<'GRAPHQL'
            mutation draftOrderUpdate($id: ID!, $input: DraftOrderInput!) {
                draftOrderUpdate(id: $id, input: $input) {
                    draftOrder { id name invoiceUrl }
                    userErrors { field message }
                }
            }
        GRAPHQL;
        $data = $this->graphql->query($mutation, ['id' => $quote->shopify_draft_order_gid, 'input' => $input]);
        $this->graphql->assertNoUserErrors($data['draftOrderUpdate']['userErrors'] ?? [], 'Draft order update');
        $draft = $data['draftOrderUpdate']['draftOrder'] ?? [];
        $quote->update(['checkout_url' => $draft['invoiceUrl'] ?? $quote->checkout_url]);
        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'updated',
            'performed_by_type' => 'staff',
            'description' => 'Shopify draft order updated from quote',
            'meta' => ['draft_order_gid' => $quote->shopify_draft_order_gid],
        ]);
        return $quote->fresh(['items', 'customer']);
    }

    /** Fetch the current Shopify draft order so the quote can be refreshed from it. */
    public function fetchExistingDraftOrder(): array
    {
        if (! $this->quote->shopify_draft_order_gid) {
            throw new \RuntimeException('This quote does not have a Shopify draft order yet.');
        }
        $query = <<<'GRAPHQL'
            query draftOrderForQuoteSync($id: ID!) {
                draftOrder(id: $id) {
                    id name email totalPrice
                    lineItems(first: 100) {
                        edges { node { id title quantity sku originalUnitPrice variant { id product { id title featuredImage { url } } } } }
                    }
                }
            }
        GRAPHQL;
        $data = $this->graphql->query($query, ['id' => $this->quote->shopify_draft_order_gid]);
        return $data['draftOrder'] ?? [];
    }

    /**
     * Sends the draft order invoice to the customer's email via Shopify.
     */
    public function sendInvoice(): void
    {
        $mutation = <<<'GRAPHQL'
            mutation draftOrderInvoiceSend($id: ID!) {
                draftOrderInvoiceSend(id: $id) {
                    draftOrder { id }
                    userErrors { field message }
                }
            }
        GRAPHQL;

        $data = $this->graphql->query($mutation, ['id' => $this->quote->shopify_draft_order_gid]);

        $this->graphql->assertNoUserErrors(
            $data['draftOrderInvoiceSend']['userErrors'] ?? [],
            'Sending draft order invoice'
        );
    }

    private function extractNumericId(string $gid): string
    {
        // gid://shopify/DraftOrder/123456789 -> 123456789
        $parts = explode('/', $gid);

        return end($parts);
    }
}