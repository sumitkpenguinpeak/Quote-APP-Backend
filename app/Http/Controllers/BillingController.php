<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\BillingService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Class BillingController
 *
 * Drives the Shopify Billing API flow for the Free / Starter / Pro plans:
 * creating charges for paid plans, handling the merchant's approval /
 * decline decision, cancelling back to Free, and exposing the current plan
 * status to the frontend.
 */
class BillingController extends Controller
{
    public function __construct(protected BillingService $billingService)
    {
    }

    /**
     * GET /api/billing?plan=starter|pro
     *
     * Creates a subscription charge for the chosen paid plan and returns
     * the confirmation URL the merchant must approve.
     */
    public function create(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        // Free pan select thai shake (Shopify approve screen sathe), etle badha plan keys valid.
        $planKeys = array_keys(config('shopify.plans'));

        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:' . implode(',', $planKeys)],
        ]);
        $plan = config("shopify.plans.{$validated['plan']}");

        try {
            $confirmationUrl = $this->billingService->createSubscription($shop, $validated['plan']);
        } catch (GuzzleException|\RuntimeException $exception) {
            Log::error('Failed to create Shopify subscription', [
                'shop' => $shop->shop,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start billing. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'confirmation_url' => $confirmationUrl,
            'plan' => $validated['plan'],
            'plan_name' => $plan['name'],
            'price' => $plan['price'],
        ]);
    }

    /**
     * GET /api/billing/callback
     *
     * Shopify redirects the merchant here (via the returnUrl passed to
     * appSubscriptionCreate) after they approve or decline the charge.
     * We re-sync the subscription status directly from Shopify - never
     * trust the redirect alone - then send the merchant to the frontend.
     */
    public function callback(Request $request): RedirectResponse
    {
        $request->validate(['shop' => ['required', 'string']]);

        $shopDomain = strtolower(trim($request->query('shop')));
        $shop = Shop::where('shop', $shopDomain)->first();

        $shopify = app(\App\Services\ShopifyService::class);

        if (! $shopify->isValidShopDomain($shopDomain) || ! $shop || ! $shop->access_token) {
            abort(404);
        }

        try {
            $isActive = $this->billingService->syncSubscriptionStatus($shop);
        } catch (GuzzleException $exception) {
            Log::error('Failed to sync subscription status after billing callback', [
                'shop' => $shopDomain,
                'error' => $exception->getMessage(),
            ]);
            $isActive = false;
        }

        // Approve karyu -> dashboard. Decline karyu: haju plan select nathi to pricing page, nahi to Billing page.
        $shop->refresh();
        $destination = $isActive ? '/dashboard' : ($shop->hasSelectedPlan() ? '/billing' : '/pricing');

        // Back into the embedded app inside the Shopify admin.
        return redirect()->away($shopify->adminAppUrl($shopDomain, $destination));
    }

    /**
     * GET /api/billing/status
     *
     * Returns the shop's current plan / billing status so the frontend can
     * render the Billing page without needing to hit Shopify directly.
     */
    public function status(Request $request): JsonResponse
    { 
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
    
        return response()->json([
            'success' => true,
            'data' => [
                'plan_key' => $shop->planKey(),
                'plan_name' => $shop->planConfig()['name'] ?? null,
                'price' => $shop->planConfig()['price'] ?? 0,
                'billing_status' => $shop->billing_status,
                'plan_selected' => $shop->hasSelectedPlan(),
                'is_subscribed' => $shop->hasActiveSubscription(),
                'features' => $shop->planConfig()['features'] ?? [],
                'quote_limit' => $shop->quoteLimit(),
                'quotes_used' => $shop->quotesThisMonth(),
            ],
        ]);
    }

    /**
     * POST /api/billing/cancel
     *
     * Cancels the paid subscription on Shopify and moves the shop back to
     * the Free plan (the "Switch to Free" button on the Billing page).
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        try {
            $this->billingService->cancelSubscription($shop);
        } catch (GuzzleException|\RuntimeException $exception) {
            Log::error('Failed to cancel Shopify subscription', [
                'shop' => $shop->shop,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to switch to the Free plan. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Subscription cancelled. You are now on the Free plan.',
        ]);
    }
}
