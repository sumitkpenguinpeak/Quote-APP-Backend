<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ShopifyGraphQLService;

/**
 * Class DashboardController
 *
 * Serves the data displayed on the merchant-facing Dashboard page. Only
 * reachable by shops that have passed VerifyShop + VerifyBilling.
 */
class DashboardController extends Controller
{
    /**
     * GET /api/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        return response()->json([
            'success' => true,
            'data' => [
                'store_name' => $shop->shop,
                'current_plan' => $shop->planConfig()['name'] ?? null,
                'plan_key' => $shop->planKey(),
                'billing_status' => $shop->billing_status,
                'installation_date' => optional($shop->installed_at)->toDateTimeString(),
                'shopify_plan' => $shop->shopify_plan,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/app-embed
     *
     * Live theme (main) na config/settings_data.json mathi check kare chhe ke "Quote App" app embed
     * theme editor ma ON chhe ke OFF. Result: { status: 'on' | 'off' | 'unknown' }.
     * Mate `read_themes` scope joiye (shopify.app.toml).
     */
    public function appEmbed(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $handle = 'quote-app-embed'; // extensions/quote-app-theme/shopify.extension.toml no handle

        $query = <<<'GRAPHQL'
            query MainThemeSettings {
                themes(first: 1, roles: [MAIN]) {
                    nodes {
                        id
                        files(filenames: ["config/settings_data.json"]) {
                            nodes {
                                body {
                                    ... on OnlineStoreThemeFileBodyText { content }
                                }
                            }
                        }
                    }
                }
            }
            GRAPHQL;

        try {
            $data = ShopifyGraphQLService::for($shop)->query($query);
        } catch (\Throwable $e) {
            Log::warning('App embed status check failed', ['shop' => $shop->shop, 'error' => $e->getMessage()]);

            $msg = $e->getMessage();
            $denied = stripos($msg, 'access denied') !== false
                || stripos($msg, 'scope') !== false
                || str_contains($msg, 'HTTP 403')
                || str_contains($msg, 'HTTP 401');

            return response()->json(['success' => true, 'data' => array_filter([
                'status' => 'unknown',
                'reason' => $denied ? 'missing_scope' : 'shopify_error',
                'hint' => $denied ? 'Approve the read_themes scope (deploy the app and have the merchant re-approve it).' : null,
                'message' => config('app.debug') ? $msg : null,
            ])]);
        }

        $content = data_get($data, 'themes.nodes.0.files.nodes.0.body.content');
        if (! is_string($content) || $content === '') {
            return response()->json(['success' => true, 'data' => ['status' => 'unknown', 'reason' => 'no_settings_file']]);
        }

        // settings_data.json ni upar /* ... */ comment hoy shake
        $json = json_decode(preg_replace('#^\s*/\*.*?\*/#s', '', $content), true);
        if (! is_array($json)) {
            return response()->json(['success' => true, 'data' => ['status' => 'unknown', 'reason' => 'invalid_json']]);
        }

        $current = $json['current'] ?? [];
        if (is_string($current)) { // preset name
            $current = $json['presets'][$current] ?? [];
        }

        $status = 'off'; // block j nathi ma = kyarey on nathi karyu
        foreach (($current['blocks'] ?? []) as $block) {
            if (str_contains((string) ($block['type'] ?? ''), "/blocks/{$handle}/")) {
                $status = ! empty($block['disabled']) ? 'off' : 'on';
                break;
            }
        }

        return response()->json(['success' => true, 'data' => ['status' => $status]]);
    }

    /**
     * GET /api/dashboard/stats?days=7|30|90
     *
     * Dashboard na KPI cards, charts ane top lists mate:
     *  - total quote value / total quotes / rejected / converted / conversion rate
     *  - series: dar divas na quotes, value ane conversion rate (chart mate)
     *  - top_products: converted quotes na top products
     *  - top_customers: sauthi vadhu quotes vala customers
     *  - lost_profit_90d: pachhla 90 divas ma apayelo discount
     */
    public function stats(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $days = (int) $request->query('days', 7);
        $days = in_array($days, [7, 30, 90], true) ? $days : 7;

        // aaj sahit chhella $days divas (aaj na divas no 00:00 thi)
        $start = now()->subDays($days - 1)->startOfDay();

        $inRange = fn () => Quote::where('shop_id', $shop->id)->where('created_at', '>=', $start);

        $totalQuotes = $inRange()->count();
        $converted = $inRange()->where('status', Quote::STATUS_CONVERTED)->count();
        $rejected = $inRange()->where('status', Quote::STATUS_REJECTED)->count();

        // NOTE: products ni cost price DB ma nathi, etle margin calculate nathi thati.
        // Hamna "lost profit" = pachhla 90 divas ma quotes par apayelo total discount.
        $lostProfit = Quote::where('shop_id', $shop->id)
            ->where('created_at', '>=', now()->subDays(89)->startOfDay())
            ->sum('discount_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'days' => $days,
                'total_quote_value' => round((float) $inRange()->sum('total'), 2),
                'total_draft_orders' => $inRange()->whereNotNull('shopify_draft_order_id')->count(),
                'total_quotes' => $totalQuotes,
                'total_quotes_rejected' => $rejected,
                'total_quotes_converted' => $converted,
                'conversion_rate' => $totalQuotes > 0 ? round($converted / $totalQuotes * 100, 1) : 0,
                'lost_profit_90d' => round((float) $lostProfit, 2),
                'series' => $this->dailySeries($inRange, $start, $days),
                'top_products' => $this->topProducts($shop, $start),
                'top_customers' => $this->topCustomers($shop, $start),
                'currency' => $shop->currency ?? 'USD',
                'current_plan' => $shop->planConfig()['name'] ?? null,
                'plan_key' => $shop->planKey(),
                'quote_limit' => $shop->quoteLimit() === null
                    ? 'Unlimited'
                    : $shop->quotesThisMonth() . ' / ' . $shop->quoteLimit() . ' this month',
                'shop' => $shop->shop,
            ],
        ]);
    }

    /**
     * Dar divas mate ek point (je divas ma quote na hoy tya 0), chart ni line tuti na jay te mate.
     * conversion_rate = te divas e banela quotes mathi kai % converted thaya.
     */
    private function dailySeries(callable $inRange, $start, int $days): array
    {
        $daily = $inRange()
            ->selectRaw(
                'DATE(created_at) as day, COUNT(*) as quotes, COALESCE(SUM(total), 0) as value, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as converted',
                [Quote::STATUS_CONVERTED]
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $row = $daily->get($date);
            $quotes = (int) ($row->quotes ?? 0);

            $series[] = [
                'date' => $date,
                'quotes' => $quotes,
                'value' => round((float) ($row->value ?? 0), 2),
                'conversion_rate' => $quotes > 0 ? round(((int) $row->converted) / $quotes * 100, 1) : 0,
            ];
        }

        return $series;
    }

    /**
     * Converted quotes ma sauthi vadhu value vala top 5 products.
     */
    private function topProducts(Shop $shop, $start): array
    {
        return DB::table('quote_items')
            ->join('quotes', 'quotes.id', '=', 'quote_items.quote_id')
            ->where('quotes.shop_id', $shop->id)
            ->where('quotes.status', Quote::STATUS_CONVERTED)
            ->where('quotes.created_at', '>=', $start)
            ->groupBy('quote_items.shopify_product_id')
            ->selectRaw(
                'MAX(quote_items.product_title) as title, MAX(quote_items.image_url) as image, '
                . 'SUM(quote_items.quantity) as quantity, COALESCE(SUM(quote_items.line_total), 0) as value'
            )
            ->orderByDesc('value')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'title' => $r->title,
                'image' => $r->image,
                'quantity' => (int) $r->quantity,
                'value' => round((float) $r->value, 2),
            ])
            ->all();
    }

    /**
     * Sauthi vadhu quotes mokalnara top 5 customers.
     */
    private function topCustomers(Shop $shop, $start): array
    {
        return Quote::where('shop_id', $shop->id)
            ->where('created_at', '>=', $start)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as quotes_count, COALESCE(SUM(total), 0) as value')
            ->orderByDesc('quotes_count')
            ->orderByDesc('value')
            ->limit(5)
            ->with('customer')
            ->get()
            ->map(function ($q) {
                $c = $q->customer;
                $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));

                return [
                    'name' => $name !== '' ? $name : ($c->company_name ?? ''),
                    'email' => $c->email ?? '',
                    'quotes' => (int) $q->quotes_count,
                    'value' => round((float) $q->value, 2),
                ];
            })
            ->all();
    }
}