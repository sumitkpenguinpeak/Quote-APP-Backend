<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * GET /api/products?search=...&search_by=all|title|sku|vendor&first=25&after=CURSOR
     *
     * Store na live products Shopify Admin GraphQL mathi lave chhe ane dar ek VARIANT ne
     * ek row tarike moklе chhe (Select products popup mate).
     *
     * Response:
     * {
     *   "data": [{ product_id, variant_id, product_title, variant_title, sku, image_url, price, available }],
     *   "next_cursor": "..." | null
     * }
     *
     * Shopify app scope: read_products
     */
    public function index(Request $request)
    {
        $shop = $this->toShop($request['shop']);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'search_by' => ['nullable', 'in:all,title,sku,vendor'],
            'first' => ['nullable', 'integer', 'min:1', 'max:50'],
            'after' => ['nullable', 'string', 'max:255'],
        ]);

        $query = <<<'GQL'
        query ($first: Int!, $after: String, $query: String) {
          products(first: $first, after: $after, query: $query, sortKey: TITLE) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              title
              featuredMedia { preview { image { url } } }
              variants(first: 20) {
                nodes {
                  id
                  title
                  sku
                  price
                  inventoryQuantity
                  image { url }
                }
              }
            }
          }
        }
        GQL;

        $json = $this->shopifyGraphql($shop, $query, [
            'first' => $request->integer('first', 25),
            'after' => $request->query('after') ?: null,
            'query' => $this->buildSearchQuery((string) $request->query('search', ''), (string) $request->query('search_by', 'all')),
        ]);

        $products = data_get($json, 'data.products', []);
        $rows = [];

        foreach (data_get($products, 'nodes', []) as $product) {
            $productImage = data_get($product, 'featuredMedia.preview.image.url');

            foreach (data_get($product, 'variants.nodes', []) as $variant) {
                $rows[] = [
                    'product_id' => Str::afterLast($product['id'], '/'),
                    'variant_id' => Str::afterLast($variant['id'], '/'),
                    'product_title' => $product['title'],
                    'variant_title' => $variant['title'],
                    'sku' => $variant['sku'] ?? '',
                    'image_url' => data_get($variant, 'image.url') ?: $productImage,
                    'price' => (float) ($variant['price'] ?? 0),
                    'available' => $variant['inventoryQuantity'] ?? null,
                ];
            }
        }

        $hasNext = (bool) data_get($products, 'pageInfo.hasNextPage', false);

        return response()->json([
            'data' => $rows,
            'next_cursor' => $hasNext ? data_get($products, 'pageInfo.endCursor') : null,
        ]);
    }

    /**
     * Middleware `shop` attribute kyarek Shop model hoy chhe, kyarek fakt domain (string).
     * Bey case handle kare chhe. Domain fakt middleware na attribute mathi j le chhe
     * (request ?shop= par bharoso nathi karto, jethi koi bijani shop na products na vanchi shake).
     */
    private function toShop(mixed $shop): Shop
    {
        if ($shop instanceof Shop) {
            return $shop;
        }

        abort_unless(is_string($shop) && $shop !== '', 401, 'Shop not authenticated.');

        $table = (new Shop)->getTable();
        foreach (['shop', 'shop_domain', 'domain', 'myshopify_domain', 'name'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $found = Shop::where($column, $shop)->first();
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Shop not found.');
    }

    /**
     * Shopify search syntax. Special characters kadhi nakhe chhe (injection thi bachva), pachhi
     * dar ek shabd par prefix search (word*) lagave chhe.
     */
    private function buildSearchQuery(string $term, string $by): string
    {
        $parts = ['status:active'];

        $clean = preg_replace('/[^\p{L}\p{N}\-_.]/u', ' ', $term);
        $words = preg_split('/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $w) {
            $parts[] = match ($by) {
                'title' => "title:{$w}*",
                'sku' => "sku:{$w}*",
                'vendor' => "vendor:{$w}*",
                default => "{$w}*",
            };
        }

        return implode(' AND ', $parts);
    }

    /**
     * Shopify Admin GraphQL call.
     *
     * NOTE: Shop model na field names (domain / access token) tamara pramane badlo.
     * Jo tame osiset/laravel-shopify vaparo cho to aa method ni jagyae:
     *     return $shop->api()->graph($query, $variables)['body']->toArray();
     */
    private function shopifyGraphql(mixed $shop, string $query, array $variables): array
    {
        $shop = $this->toShop($shop); // string aave to pan Shop model banavi le
        // tamari shops table: `shop` = myshopify domain, `access_token`, `scope`
        $domain = data_get($shop, 'shop');
        $token = data_get($shop, 'access_token');
        $version = config('shopify.api_version', '2026-10');

        abort_unless($domain && $token, 500, 'Shop credentials missing.');

        // install vakhate je scopes malya te `scope` column ma hoy chhe (write_products => read_products pan chale)
        abort_if(
            ! \App\Models\Shop::scopeGranted((string) data_get($shop, 'scope', ''), 'read_products'),
            403,
            'This store does not have the read_products scope. Uninstall the app from the store and install it again.'
        );

        $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->timeout(20)
            ->post("https://{$domain}/admin/api/{$version}/graphql.json", [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            Log::warning('Shopify products request failed', [
                'domain' => $domain,
                'api_version' => $version,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // debug mode ma asli karan response ma dekhay (production ma generic message)
            abort(502, config('app.debug')
                ? "Shopify request failed for {$domain} (HTTP {$response->status()}): " . Str::limit($response->body(), 300)
                : 'Could not load products from Shopify.');
        }

        $json = $response->json();
        // echo "<pre>";print_r($json);die;
        // abort_if(! empty($json['errors']), 502, 'Shopify error: ' . json_encode($json['errors']));

        return $json;
    }
}