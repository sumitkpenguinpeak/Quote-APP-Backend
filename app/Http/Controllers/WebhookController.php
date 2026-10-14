<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGdprRequest;
use App\Services\ShopifyService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Class WebhookController
 *
 * Receives and verifies webhooks sent by Shopify. Every request is
 * authenticated using the X-Shopify-Hmac-Sha256 header computed over the
 * raw request body before any business logic runs.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected ShopifyService $shopifyService,
        protected WebhookService $webhookService,
    ) {
    }

    /**
     * POST /api/webhooks/app-uninstalled
     *
     * Fired by Shopify the moment a merchant uninstalls the app.
     */
    public function appUninstalled(Request $request): JsonResponse
    {
        if (! $this->verifyRequest($request)) {
            return $this->unauthorizedResponse();
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain')));
        $payload = $request->json()->all();

        $this->webhookService->logWebhook($shopDomain, 'app/uninstalled', $payload);
        $this->webhookService->handleAppUninstalled($shopDomain);

        // Shopify expects a fast 200 OK - always acknowledge receipt.
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/webhooks/subscription-update
     *
     * Fired whenever a recurring application charge's status changes
     * (e.g. ACTIVE, CANCELLED, DECLINED, EXPIRED, FROZEN).
     */
    public function subscriptionUpdate(Request $request): JsonResponse
    {
        if (! $this->verifyRequest($request)) {
            return $this->unauthorizedResponse();
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain')));
        $payload = $request->json()->all();

        $this->webhookService->logWebhook($shopDomain, 'app_subscriptions/update', $payload);
        $this->webhookService->handleSubscriptionUpdate($shopDomain, $payload);

        return response()->json(['success' => true]);
    }

    /** POST /api/webhooks/customers-data-request  (mandatory GDPR webhook) */
    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->handlePrivacyWebhook($request, 'customers/data_request');
    }

    /** POST /api/webhooks/customers-redact  (mandatory GDPR webhook) */
    public function customersRedact(Request $request): JsonResponse
    {
        return $this->handlePrivacyWebhook($request, 'customers/redact');
    }

    /** POST /api/webhooks/shop-redact  (mandatory GDPR webhook) */
    public function shopRedact(Request $request): JsonResponse
    {
        return $this->handlePrivacyWebhook($request, 'shop/redact');
    }

    /**
     * Verify the HMAC (401 if invalid, as Shopify requires), acknowledge with 200
     * immediately and do the actual export / deletion in a job.
     * The payload (customer PII) is deliberately NOT written to webhook_logs.
     */
    protected function handlePrivacyWebhook(Request $request, string $topic): JsonResponse
    {
        if (! $this->verifyRequest($request)) {
            return $this->unauthorizedResponse();
        }

        $payload = $request->json()->all();
        $shopDomain = strtolower(trim((string) ($request->header('X-Shopify-Shop-Domain') ?: ($payload['shop_domain'] ?? ''))));

        $this->webhookService->logWebhook($shopDomain, $topic, []);

        dispatch(new ProcessGdprRequest($topic, $shopDomain, $payload));

        return response()->json(['success' => true]);
    }

    /**
     * Generic catch-all webhook receiver: logs any topic that does not yet
     * have a dedicated handler above, so nothing is silently dropped.
     */
    public function generic(Request $request): JsonResponse
    {
        if (! $this->verifyRequest($request)) {
            return $this->unauthorizedResponse();
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain')));
        $topic = (string) $request->header('X-Shopify-Topic', 'unknown');

        // Unknown topic: keep the audit row but not the payload (may contain PII).
        $this->webhookService->logWebhook($shopDomain, $topic, []);

        Log::info('Received unhandled Shopify webhook', ['shop' => $shopDomain, 'topic' => $topic]);

        return response()->json(['success' => true]);
    }

    /**
     * Verify the raw-body HMAC signature on an incoming webhook request.
     */
    protected function verifyRequest(Request $request): bool
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $rawBody = $request->getContent();

        $valid = $this->shopifyService->verifyWebhookHmac($rawBody, $hmacHeader);

        if (! $valid) {
            Log::warning('Webhook HMAC verification failed', [
                'shop' => $request->header('X-Shopify-Shop-Domain'),
                'topic' => $request->header('X-Shopify-Topic'),
            ]);
        }

        return $valid;
    }

    protected function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
    }
}
