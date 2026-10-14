<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\QuoteButtonRuleController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteFormFieldController;
use App\Http\Controllers\Api\QuoteItemController;
use App\Http\Controllers\Api\QuoteMessageController;
use App\Http\Controllers\Api\ShopSettingController;
use App\Http\Controllers\Api\StaffMemberController;
use App\Http\Controllers\Storefront\QuoteRequestController;
use App\Http\Controllers\Storefront\ShippingRateController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\EmailConfigController;
use App\Http\Controllers\Api\QuoteInvoiceController;

/*
|--------------------------------------------------------------------------
| API Routes - Review Pro App
|--------------------------------------------------------------------------
|
| These routes are loaded by App\Providers\RouteServiceProvider and are
| all prefixed with /api. Public routes (install, OAuth callback, and
| webhooks) do not require shop/billing middleware since they either
| establish the shop's identity or are authenticated via Shopify's own
| HMAC signature instead.
|
*/

// -----------------------------------------------------------------------
// Installation / OAuth (public - these routes establish the shop's session)
// -----------------------------------------------------------------------
Route::middleware('web')->group(function () {
    Route::get('/install', [AuthController::class, 'install'])->name('shopify.install');
    Route::get('/auth/callback', [AuthController::class, 'callback'])->name('shopify.callback');
});

// Google redirects the merchant's browser here after the Gmail consent screen
// (public - the request is tied to a shop through the one-time `state` nonce).
Route::get('/email-config/callback', [EmailConfigController::class, 'callback'])->name('email-config.callback');

// -----------------------------------------------------------------------
// Webhooks (public - authenticated via X-Shopify-Hmac-Sha256 header)
// -----------------------------------------------------------------------
Route::prefix('webhooks')->group(function () {
    Route::post('/app-uninstalled', [WebhookController::class, 'appUninstalled'])->name('webhooks.app-uninstalled');
    Route::post('/subscription-update', [WebhookController::class, 'subscriptionUpdate'])->name('webhooks.subscription-update');

    // Mandatory GDPR / privacy webhooks (compliance topics in shopify.app.toml)
    Route::post('/customers-data-request', [WebhookController::class, 'customersDataRequest'])->name('webhooks.customers-data-request');
    Route::post('/customers-redact', [WebhookController::class, 'customersRedact'])->name('webhooks.customers-redact');
    Route::post('/shop-redact', [WebhookController::class, 'shopRedact'])->name('webhooks.shop-redact');

    Route::post('/{topic}', [WebhookController::class, 'generic'])->name('webhooks.generic');
});

// -----------------------------------------------------------------------
// Billing charge creation + Shopify return URL only require a valid shop,
// not an already-active subscription (otherwise no one could ever subscribe).
// -----------------------------------------------------------------------
// The return URL is a top-level browser redirect from Shopify (no JWT available). It
// is safe without one: it only re-syncs the subscription state FROM Shopify and redirects.
Route::get('/billing/callback', [BillingController::class, 'callback'])->name('billing.callback');

Route::middleware(['verify.shop'])->group(function () {
    Route::get('/session', [AuthController::class, 'session'])->name('shopify.session');
    Route::get('/billing', [BillingController::class, 'create'])->name('billing.create');
    Route::get('/billing/status', [BillingController::class, 'status'])->name('billing.status');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
});

// -----------------------------------------------------------------------
// Fully protected app routes - require an installed shop AND an active
// Review Pro Basic subscription.
// -----------------------------------------------------------------------
Route::middleware(['verify.shop', 'verify.billing'])->group(function () {
    Route::get('/dashboard/app-embed', [DashboardController::class, 'appEmbed']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
    Route::put('/quotes/{quote}', [QuoteController::class, 'update']);
    Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);
    Route::patch('/quotes/{quote}/assign', [QuoteController::class, 'assignStaff']);
    Route::get('quotes/{quote}/export-pdf', [QuoteController::class, 'exportPdf']);
    Route::get('/quotes/{quote}/shipping-rates', [QuoteController::class, 'shippingRates']);
    Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy']);
    Route::post('/quotes/{quote}/duplicate', [QuoteController::class, 'duplicate']);
    Route::post('/quotes/{quote}/send-proposal', [QuoteController::class, 'sendProposal']);
    Route::get('products', [ProductController::class, 'index']);
    Route::post('/quotes/{quote}/draft-order', [QuoteController::class, 'createDraftOrder']);
    Route::post('/quotes/{quote}/sync-to-draft-order', [QuoteController::class, 'syncToDraftOrder']);
    Route::post('/quotes/{quote}/sync-from-draft-order', [QuoteController::class, 'syncFromDraftOrder']);
    Route::get('quotes/{quote}/invoice-pdf', [QuoteInvoiceController::class, 'download']);
    Route::post('quotes/{quote}/send-invoice', [QuoteInvoiceController::class, 'send']);

    Route::post('/quotes/{quote}/messages', [QuoteMessageController::class, 'store']);
    Route::patch('/quotes/{quote}/items/{item}/offer', [QuoteItemController::class, 'updateOffer']);

    Route::get('/settings', [ShopSettingController::class, 'show']);
    Route::put('/settings', [ShopSettingController::class, 'update']);

    Route::apiResource('button-rules', QuoteButtonRuleController::class)->except(['show']);

    Route::get('/form-fields', [QuoteFormFieldController::class, 'index']);
    Route::post('/form-fields', [QuoteFormFieldController::class, 'store']);
    Route::put('/form-fields/{field}', [QuoteFormFieldController::class, 'update']);
    Route::delete('/form-fields/{field}', [QuoteFormFieldController::class, 'destroy']);
    Route::patch('/form-fields/reorder', [QuoteFormFieldController::class, 'reorder']); 

    Route::get('/staff', [StaffMemberController::class, 'index']);
    Route::post('/staff', [StaffMemberController::class, 'store']);
    Route::delete('/staff/{staff}', [StaffMemberController::class, 'destroy']);

    Route::post('/storefront/create-cart', [QuoteRequestController::class, 'createCart']);
    Route::post(
    '/create-storefront-token',
    [QuoteRequestController::class, 'createStorefrontToken']
);

    Route::get('/email-templates', [EmailTemplateController::class, 'index']);
    Route::get('/email-templates/{type}', [EmailTemplateController::class, 'show']);
    Route::put('/email-templates/{type}', [EmailTemplateController::class, 'update']);
    Route::delete('/email-templates/{type}', [EmailTemplateController::class, 'destroy']);
    Route::post('/email-templates/{type}/test', [EmailTemplateController::class, 'sendTest']);

    // Email Configuration (connect / disconnect the Gmail sender account)
    Route::get('/email-config', [EmailConfigController::class, 'show']);
    Route::get('/email-config/connect', [EmailConfigController::class, 'connect']);
    Route::delete('/email-config', [EmailConfigController::class, 'destroy']);

});


// -----------------------------------------------------------------------
// Public storefront endpoints - reached ONLY through the Shopify App Proxy
// (https://{shop}/apps/quote-app/* -> /api/proxy/*). Every request carries a
// Shopify-signed `signature`; the shop comes from the signed query.
// -----------------------------------------------------------------------
Route::prefix('proxy')->middleware(['verify.app-proxy', 'verify.billing', 'throttle:60,1'])->group(function () {
    Route::get('/collections', [ShopSettingController::class, 'collections']);
    Route::post('/quotes', [QuoteRequestController::class, 'store']);
    Route::post('/shipping-rates', [ShippingRateController::class, 'index']);
});