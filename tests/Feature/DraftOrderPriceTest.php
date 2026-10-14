<?php
namespace Tests\Feature;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Shop;
use App\Services\ShopifyDraftOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftOrderPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_quoted_price_is_sent_as_price_override(): void
    {
        Http::fake(['*' => Http::response(['data' => ['draftOrderCreate' => [
            'draftOrder' => ['id' => 'gid://shopify/DraftOrder/9', 'name' => '#D9', 'invoiceUrl' => 'https://x/inv', 'totalPrice' => '1'],
            'userErrors' => [],
        ]]], 200)]);

        $shop = Shop::create(['shop' => 't.myshopify.com', 'access_token' => 'tok', 'is_active' => true]);
        $quote = Quote::forceCreate(['shop_id' => $shop->id, 'quote_number' => 'Q-1', 'status' => 'pending', 'currency' => 'USD',
            'tax_type' => 'percentage', 'tax_value' => 10, 'tax_name' => 'GST']);
        QuoteItem::forceCreate(['quote_id' => $quote->id, 'shopify_product_id' => '1', 'shopify_variant_id' => '111',
            'product_title' => 'Liquid', 'quantity' => 1, 'original_price' => 749.95, 'customer_requested_price' => 200, 'line_total' => 200]);
        QuoteItem::forceCreate(['quote_id' => $quote->id, 'shopify_product_id' => '2', 'shopify_variant_id' => '222',
            'product_title' => 'Out of stock', 'quantity' => 3, 'original_price' => 885.95, 'customer_requested_price' => 885.95, 'line_total' => 2657.85]);
        QuoteItem::forceCreate(['quote_id' => $quote->id, 'shopify_product_id' => '3', 'shopify_variant_id' => null,
            'product_title' => 'Deleted product', 'quantity' => 2, 'original_price' => 50, 'customer_requested_price' => 40, 'line_total' => 80]);

        ShopifyDraftOrderService::for($quote)->createFromQuote();

        Http::assertSent(function (Request $r) {
            $this->assertStringContainsString('/admin/api/2026-07/graphql.json', $r->url());
            $input = $r->data()['variables']['input'];
            $lines = $input['lineItems'];
            // tax have custom line item tarike nathi jatu; Shopify potani tax calculate kare
            $this->assertCount(3, $lines);
            $this->assertFalse($input['taxExempt']);
            echo "\n" . json_encode($lines) . "\n";

            $this->assertSame('gid://shopify/ProductVariant/111', $lines[0]['variantId']);
            $this->assertSame(['amount' => '200.00', 'currencyCode' => 'USD'], $lines[0]['priceOverride']);
            $this->assertArrayNotHasKey('originalUnitPrice', $lines[0]);
            $this->assertSame(['amount' => '885.95', 'currencyCode' => 'USD'], $lines[1]['priceOverride']);
            $this->assertSame(3, $lines[1]['quantity']);
            $this->assertArrayNotHasKey('variantId', $lines[2]);
            $this->assertSame(['amount' => '40.00', 'currencyCode' => 'USD'], $lines[2]['originalUnitPriceWithCurrency']);
            return true;
        });
    }
}
