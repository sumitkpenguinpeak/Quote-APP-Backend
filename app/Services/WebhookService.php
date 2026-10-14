<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;

/**
 * Class WebhookService
 *
 * Contains the business logic executed in response to Shopify webhooks.
 * Controllers stay thin; this service owns the actual side effects.
 */
class WebhookService
{
    /**
     * Persist an audit record for every webhook received, regardless of
     * topic, so we always have a trail of what Shopify has sent us.
     */
    public function logWebhook(string $shop, string $topic, array $payload): WebhookLog
    {
        return WebhookLog::create([
            'shop' => $shop,
            'topic' => $topic,
            'payload' => $payload,
            'received_at' => now(),
        ]);
    }

    /**
     * Handle the app/uninstalled webhook.
     *
     * Historical records are intentionally preserved - we only flip flags
     * and stamp the uninstall date, we never delete the shop row.
     */
    public function handleAppUninstalled(string $shopDomain): void
    {
        $shop = Shop::where('shop', $shopDomain)->first();

        if (! $shop) {
            Log::warning('Received app/uninstalled webhook for unknown shop', ['shop' => $shopDomain]);

            return;
        }

        $shop->update([
            'is_active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'billing_status' => Shop::BILLING_STATUS_CANCELLED,
            'plan' => config('shopify.billing.default_plan', 'free'),
            'plan_selected_at' => null, // reinstall par pachha pricing page
            'uninstalled_at' => now(),
        ]);

        // Drop the connected Gmail account (its tokens are useless once the app is uninstalled).
        $shop->emailAccount?->delete();

        Log::info('Shop uninstalled Review Pro App', [
            'shop' => $shopDomain,
            'uninstalled_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Handle the app_subscriptions/update webhook, keeping our local
     * billing_status in sync with whatever Shopify reports.
     */
    public function handleSubscriptionUpdate(string $shopDomain, array $payload): void
    {
        $shop = Shop::where('shop', $shopDomain)->first();

        if (! $shop) {
            Log::warning('Received subscription update webhook for unknown shop', ['shop' => $shopDomain]);

            return;
        }

        $subscription = $payload['app_subscription'] ?? [];
        $status = strtoupper($subscription['status'] ?? '');
        $subscriptionId = $subscription['admin_graphql_api_id'] ?? null;

        $map = [
            'ACTIVE' => Shop::BILLING_STATUS_ACTIVE,
            'CANCELLED' => Shop::BILLING_STATUS_CANCELLED,
            'DECLINED' => Shop::BILLING_STATUS_DECLINED,
            'EXPIRED' => Shop::BILLING_STATUS_EXPIRED,
            'FROZEN' => Shop::BILLING_STATUS_CANCELLED,
        ];

        // When the merchant switches plans, Shopify cancels the OLD subscription
        // after the new one is active. Ignore end-of-life events for a
        // subscription that is no longer the shop's current one.
        if (
            $status !== 'ACTIVE'
            && $subscriptionId
            && $shop->billing_id
            && $shop->billing_id !== $subscriptionId
        ) {
            Log::info('Ignoring subscription webhook for a replaced subscription', [
                'shop' => $shopDomain,
                'status' => $status,
            ]);

            return;
        }

        if (isset($map[$status])) {
            $update = ['billing_status' => $map[$status]];

            if ($status === 'ACTIVE') {
                $planKey = app(BillingService::class)->planKeyFromSubscriptionName($subscription['name'] ?? '');
                if ($planKey) {
                    $update['plan'] = $planKey;
                }
                if ($subscriptionId) {
                    $update['billing_id'] = $subscriptionId;
                }
                $update['plan_selected_at'] = $shop->plan_selected_at ?? now();
            } else {
                $update['plan'] = config('shopify.billing.default_plan', 'free');
            }

            $shop->update($update);

            Log::info('Shop subscription status updated via webhook', [
                'shop' => $shopDomain,
                'status' => $map[$status],
            ]);
        }
    }
}
