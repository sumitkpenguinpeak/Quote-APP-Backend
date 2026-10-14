<?php

namespace App\Services;

use App\Models\Shop;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Class BillingService
 *
 * Wraps the Shopify Billing API (AppSubscriptionCreate / current app
 * installation query) so the rest of the application never talks to
 * Shopify's GraphQL billing endpoints directly.
 */
class BillingService
{
    public function __construct(protected ShopifyService $shopifyService)
    {
    }

    /**
     * Create a recurring application charge (subscription) for the given
     * shop and return the confirmation URL the merchant must visit to
     * approve the charge.
     *
     * @throws GuzzleException
     */
    public function createSubscription(Shop $shop, string $planKey): string
    {
        $config = config('shopify.billing');
        $plan = $this->plan($planKey);
        $isFree = (float) $plan['price'] <= 0;
        $returnUrl = config('shopify.app_url') . '/api/billing/callback?shop=' . $shop->shop;

        $mutation = <<<'GRAPHQL'
        mutation AppSubscriptionCreate(
            $name: String!
            $lineItems: [AppSubscriptionLineItemInput!]!
            $returnUrl: URL!
            $test: Boolean
            $trialDays: Int
            $replacementBehavior: AppSubscriptionReplacementBehavior
        ) {
            appSubscriptionCreate(
                name: $name
                lineItems: $lineItems
                returnUrl: $returnUrl
                test: $test
                trialDays: $trialDays
                replacementBehavior: $replacementBehavior
            ) {
                appSubscription { id status }
                confirmationUrl
                userErrors { field message }
            }
        }
        GRAPHQL;

        $variables = [
            'name' => $plan['name'],
            'returnUrl' => $returnUrl,
            'test' => $config['test_mode'],
            // Free plan ma trial nathi
            'trialDays' => $isFree ? null : $config['trial_days'],
            // Switching plans (upgrade / downgrade) replaces the current subscription right away.
            'replacementBehavior' => 'APPLY_IMMEDIATELY',
            'lineItems' => [[
                'plan' => $isFree
                    // Free: $0 recurring Shopify reject kare chhe, etle usage-only subscription (kyarey usage charge nathi thatu)
                    ? ['appUsagePricingDetails' => [
                        'terms' => $config['free_plan_terms'],
                        'cappedAmount' => [
                            'amount' => $config['free_plan_cap'],
                            'currencyCode' => $config['currency'],
                        ],
                    ]]
                    : ['appRecurringPricingDetails' => [
                        'price' => [
                            'amount' => $plan['price'],
                            'currencyCode' => $config['currency'],
                        ],
                        'interval' => 'EVERY_30_DAYS',
                    ]],
            ]],
        ];

        $result = $this->shopifyService->graphqlRequest(
            $shop->shop,
            app(ShopifyTokenService::class)->ensureFresh($shop),
            $mutation,
            $variables
        );

        $payload = $result['data']['appSubscriptionCreate'] ?? [];
        $userErrors = $payload['userErrors'] ?? [];

        if (! empty($userErrors)) {
            Log::error('Shopify billing subscription creation failed', [
                'shop' => $shop->shop,
                'errors' => $userErrors,
            ]);

            throw new \RuntimeException(
                'Unable to create subscription: ' . collect($userErrors)->pluck('message')->implode(', ')
            );
        }

        $shop->update([
            'billing_id' => $payload['appSubscription']['id'] ?? null,
            'billing_status' => Shop::BILLING_STATUS_PENDING,
        ]);

        return $payload['confirmationUrl'] ?? '';
    }

    /**
     * Query Shopify for the shop's currently active subscriptions and
     * update the local billing_status accordingly. Returns true if the
     * shop has at least one active subscription.
     *
     * @throws GuzzleException
     */
    public function syncSubscriptionStatus(Shop $shop): bool
    {
        $query = <<<'GRAPHQL'
        query {
            currentAppInstallation {
                activeSubscriptions {
                    id
                    name
                    status
                }
            }
        }
        GRAPHQL;

        $result = $this->shopifyService->graphqlRequest($shop->shop, app(ShopifyTokenService::class)->ensureFresh($shop), $query);

        $subscriptions = $result['data']['currentAppInstallation']['activeSubscriptions'] ?? [];

        $active = collect($subscriptions)->firstWhere('status', 'ACTIVE');

        $planKey = $active ? $this->planKeyFromSubscriptionName($active['name'] ?? '') : null;

        if ($active && $planKey) {
            $shop->update([
                'billing_status' => Shop::BILLING_STATUS_ACTIVE,
                'billing_id' => $active['id'],
                'plan' => $planKey,
                // Merchant e approve kari didhu = plan select thai gayo (Free ma pan)
                'plan_selected_at' => $shop->plan_selected_at ?? now(),
            ]);

            return true;
        }

        if ($active) {
            Log::warning('Active Shopify subscription does not match any plan', [
                'shop' => $shop->shop,
                'subscription' => $active['name'] ?? null,
            ]);
        }

        // No (known) active subscription found - only downgrade if we are
        // not still waiting on merchant approval. The shop falls back to Free.
        if ($shop->billing_status === Shop::BILLING_STATUS_ACTIVE) {
            $shop->update([
                'billing_status' => Shop::BILLING_STATUS_EXPIRED,
                'plan' => config('shopify.billing.default_plan', 'free'),
            ]);
        }

        return false;
    }

    /**
     * Mark a subscription as declined by the merchant.
     */
    public function markDeclined(Shop $shop): void
    {
        $shop->update(['billing_status' => Shop::BILLING_STATUS_DECLINED]);
    }

    /**
     * Mark a subscription as cancelled (merchant cancelled from Shopify
     * admin, or we received an app_subscriptions/update webhook with a
     * CANCELLED status).
     */
    public function markCancelled(Shop $shop): void
    {
        $shop->update([
            'billing_status' => Shop::BILLING_STATUS_CANCELLED,
            'plan' => config('shopify.billing.default_plan', 'free'),
        ]);
    }

    /**
     * Cancel the shop's paid subscription on Shopify (so charges actually
     * stop) and drop the shop back to the Free plan.
     *
     * @throws GuzzleException
     */
    public function cancelSubscription(Shop $shop): void
    {
        if ($shop->billing_id && $shop->billing_status === Shop::BILLING_STATUS_ACTIVE) {
            $mutation = <<<'GRAPHQL'
            mutation AppSubscriptionCancel($id: ID!) {
                appSubscriptionCancel(id: $id) {
                    appSubscription { id status }
                    userErrors { field message }
                }
            }
            GRAPHQL;

            $result = $this->shopifyService->graphqlRequest(
                $shop->shop,
                app(ShopifyTokenService::class)->ensureFresh($shop),
                $mutation,
                ['id' => $shop->billing_id]
            );

            $userErrors = $result['data']['appSubscriptionCancel']['userErrors'] ?? [];

            if (! empty($userErrors)) {
                Log::error('Shopify billing subscription cancel failed', [
                    'shop' => $shop->shop,
                    'errors' => $userErrors,
                ]);

                throw new \RuntimeException(
                    'Unable to cancel subscription: ' . collect($userErrors)->pluck('message')->implode(', ')
                );
            }
        }

        $this->markCancelled($shop);
    }

    /**
     * Config of a plan (Free / Starter / Pro); throws for unknown plans.
     * Free pan Shopify approval screen mate subscription banave chhe (usage-only, $0).
     */
    public function plan(string $planKey): array
    {
        $plan = config("shopify.plans.{$planKey}");

        if (! $plan) {
            throw new \RuntimeException('Invalid plan selected.');
        }

        return $plan;
    }

    /**
     * Map a Shopify subscription name back to one of our plan keys.
     */
    public function planKeyFromSubscriptionName(string $name): ?string
    {
        foreach (config('shopify.plans', []) as $key => $plan) {
            if (strcasecmp($plan['name'], $name) === 0) {
                return $key;
            }
        }

        return config('shopify.billing.legacy_plan_names.' . $name);
    }
}
