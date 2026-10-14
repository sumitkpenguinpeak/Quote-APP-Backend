<?php

/*
 * The admin API is called same-origin (the embedded app is served from the same
 * domain) and the storefront reaches the backend through the Shopify App Proxy
 * (same-origin on the merchant's domain), so no wildcard origin is needed.
 * Add extra origins (e.g. a separate admin domain) via CORS_ALLOWED_ORIGINS.
 */
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('SHOPIFY_APP_URL', ''))))));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [
        '#^https://admin\.shopify\.com$#',
        '#^https://[a-z0-9\-]+\.myshopify\.com$#',
        // Vercel production + preview/branch URLs of this frontend project
        '#^https://quote-app-frontend[a-z0-9\-]*\.vercel\.app$#',
    ],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 600,

    // Auth is a Bearer session token, not cookies.
    'supports_credentials' => false,

];