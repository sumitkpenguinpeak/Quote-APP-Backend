<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify App Credentials
    |--------------------------------------------------------------------------
    */
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),
    'app_url' => env('SHOPIFY_APP_URL'),
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    | Comma separated list of scopes requested during installation.
    */
    'scopes' => env('SHOPIFY_SCOPES', 'read_products,write_products,read_orders,write_draft_orders,read_draft_orders,read_themes,write_themes,write_content'),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    | Where the merchant lands after a successful OAuth / billing flow.
    */
    'frontend_url' => env('FRONTEND_URL', env('SHOPIFY_APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */
    'billing' => [
        // Free trial for every paid plan (Starter / Pro). Shopify only gives the
        // remaining trial days when a shop switches between paid plans.
        'trial_days' => (int) env('SHOPIFY_BILLING_TRIAL_DAYS', 7),
        'test_mode' => filter_var(env('SHOPIFY_BILLING_TEST_MODE', false), FILTER_VALIDATE_BOOLEAN),
        'currency' => env('SHOPIFY_BILLING_CURRENCY', 'USD'),

        // Free plan is created as a usage-only subscription (Shopify rejects $0 recurring).
        'free_plan_terms' => env('SHOPIFY_BILLING_FREE_TERMS', 'Free plan - no usage charges are ever applied.'),
        'free_plan_cap' => (float) env('SHOPIFY_BILLING_FREE_CAP', 1.00),

        // Default plan for every shop that has no active paid subscription.
        'default_plan' => 'free',

        // Subscription names used by the old single-plan version. Active
        // subscriptions with these names are treated as the given plan.
        'legacy_plan_names' => [
            'Review Pro Basic' => 'starter',
        ],
    ],

    'page' => [
        'app_handle'    => env('SHOPIFY_APP_HANDLE'),          // Partner dashboard > App > Settings > Handle
        'block_handle'  => 'quote-page',                       // file name: blocks/quote-page.liquid
        'extension_uid' => env('SHOPIFY_THEME_EXTENSION_UID'), // shopify.extension.toml no uid
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing plans
    |--------------------------------------------------------------------------
    | name         - also the Shopify subscription name (used to map an active
    |                subscription back to a plan, so keep it unique)
    | price        - monthly price in billing.currency (0 = no Shopify charge)
    | quote_limit  - quotes per calendar month (null = unlimited)
    | features     - what the plan unlocks (checked by Shop::canUse())
    |                  draft_order      Convert Quote to Draft Order
    |                  sync             Sync data (quote <-> Shopify draft order)
    |                  pricing_settings Tax, Discount, Shipping
    |                  templates        Modify PDF and Mail templates
    */
    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0.00,
            'quote_limit' => 10,
            'features' => [],
        ],
        'starter' => [
            'name' => 'Starter',
            'price' => 9.99,
            'quote_limit' => 50,
            'features' => ['draft_order', 'sync'],
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 19.99,
            'quote_limit' => null,
            'features' => ['draft_order', 'sync', 'pricing_settings', 'templates'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks that are registered automatically after installation.
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        [
            'topic' => 'app/uninstalled',
            'path' => '/api/webhooks/app-uninstalled',
        ],
        [
            'topic' => 'app_subscriptions/update',
            'path' => '/api/webhooks/subscription-update',
        ],
    ],
];  