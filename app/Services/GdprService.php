<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Quote;
use App\Models\QuoteAttachment;
use App\Models\Review;
use App\Models\Shop;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Implements Shopify's mandatory privacy webhooks:
 *   customers/data_request  -> export everything we hold about the customer
 *   customers/redact        -> delete everything we hold about the customer
 *   shop/redact             -> delete everything we hold about the shop
 */
class GdprService
{
    /**
     * customers/data_request: compile the customer's data into a private JSON file
     * (local disk, not publicly served) for the merchant and record the request.
     * Returns the stored file path.
     */
    public function exportCustomerData(string $shopDomain, array $payload): ?string
    {
        $shop = Shop::where('shop', $shopDomain)->first();
        if (! $shop) {
            return null;
        }

        [$customerIds, $email] = $this->customerIdentity($shop, $payload);

        $quotes = Quote::where('shop_id', $shop->id)
            ->whereIn('customer_id', $customerIds)
            ->with(['customer', 'items', 'fieldValues', 'messages', 'activityLogs'])
            ->get();

        $export = [
            'shop' => $shopDomain,
            'data_request_id' => $payload['data_request']['id'] ?? null,
            'generated_at' => now()->toIso8601String(),
            'customers' => Customer::whereIn('id', $customerIds)->get()->toArray(),
            'quotes' => $quotes->toArray(),
            'notifications' => $email
                ? NotificationLog::where('shop_id', $shop->id)->where('recipient', $email)->get()->toArray()
                : [],
            'reviews' => $email
                ? Review::where('shop_id', $shop->id)->where('email', $email)->get()->toArray()
                : [],
        ];

        $requestId = $payload['data_request']['id'] ?? now()->timestamp;
        $path = "gdpr/{$shop->id}/data-request-{$requestId}.json";
        Storage::disk('local')->put($path, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        DB::table('gdpr_requests')->insert([
            'shop_id' => $shop->id,
            'shop' => $shopDomain,
            'type' => 'customers/data_request',
            'shopify_customer_id' => isset($payload['customer']['id']) ? (string) $payload['customer']['id'] : null,
            'export_path' => $path,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('GDPR data request exported', ['shop' => $shopDomain, 'path' => $path]);

        return $path;
    }

    /**
     * customers/redact: permanently delete the customer's quotes (items, messages,
     * attachments, logs cascade), generated PDFs/files, notification log rows,
     * reviews and the customer record itself.
     */
    public function redactCustomer(string $shopDomain, array $payload): void
    {
        $shop = Shop::where('shop', $shopDomain)->first();
        if (! $shop) {
            return; // nothing stored for this shop
        }

        [$customerIds, $email] = $this->customerIdentity($shop, $payload);

        DB::transaction(function () use ($shop, $customerIds, $email) {
            $quotes = Quote::where('shop_id', $shop->id)->whereIn('customer_id', $customerIds)->get();

            foreach ($quotes as $quote) {
                $this->deleteQuoteFiles($quote);
                $quote->delete();
            }

            if ($email) {
                NotificationLog::where('shop_id', $shop->id)->where('recipient', $email)->delete();
                Review::where('shop_id', $shop->id)->where('email', $email)->delete();
            }

            Customer::whereIn('id', $customerIds)->delete();
        });

        $this->scrubWebhookLogs($shopDomain);

        DB::table('gdpr_requests')->insert([
            'shop_id' => $shop->id,
            'shop' => $shopDomain,
            'type' => 'customers/redact',
            'shopify_customer_id' => isset($payload['customer']['id']) ? (string) $payload['customer']['id'] : null,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('GDPR customer redacted', ['shop' => $shopDomain]);
    }

    /**
     * shop/redact (48h after uninstall): delete ALL data of the shop.
     * Skipped if the shop has meanwhile reinstalled the app.
     */
    public function redactShop(string $shopDomain): void
    {
        $shop = Shop::where('shop', $shopDomain)->first();

        if ($shop && $shop->is_active) {
            Log::warning('shop/redact ignored: shop has reinstalled the app', ['shop' => $shopDomain]);

            return;
        }

        if ($shop) {
            DB::transaction(function () use ($shop) {
                foreach (Quote::where('shop_id', $shop->id)->cursor() as $quote) {
                    $this->deleteQuoteFiles($quote);
                }

                Storage::disk('local')->deleteDirectory("gdpr/{$shop->id}");

                // Explicit deletes (the FK cascades from shops would also do this).
                NotificationLog::where('shop_id', $shop->id)->delete();
                Review::where('shop_id', $shop->id)->delete();
                Quote::where('shop_id', $shop->id)->delete();
                Customer::where('shop_id', $shop->id)->delete();

                // Remaining shop-owned rows (settings, staff, form fields, button rules,
                // email templates, connected Gmail account) go via ON DELETE CASCADE.
                $shop->delete();
            });
        }

        WebhookLog::where('shop', $shopDomain)->delete();

        DB::table('gdpr_requests')->insert([
            'shop_id' => null,
            'shop' => $shopDomain,
            'type' => 'shop/redact',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('GDPR shop redacted', ['shop' => $shopDomain]);
    }

    /** @return array{0: array<int,int>, 1: ?string} local customer ids + normalised email */
    protected function customerIdentity(Shop $shop, array $payload): array
    {
        $shopifyId = isset($payload['customer']['id']) ? (string) $payload['customer']['id'] : null;
        $email = isset($payload['customer']['email']) ? strtolower(trim((string) $payload['customer']['email'])) : null;

        $ids = Customer::where('shop_id', $shop->id)
            ->where(function ($q) use ($shopifyId, $email) {
                if ($shopifyId) {
                    $q->orWhere('shopify_customer_id', $shopifyId);
                }
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->when(! $shopifyId && ! $email, fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id')
            ->all();

        return [$ids, $email];
    }

    protected function deleteQuoteFiles(Quote $quote): void
    {
        if ($quote->pdf_path) {
            Storage::disk('public')->delete($quote->pdf_path);
            Storage::disk('local')->delete($quote->pdf_path);
        }

        QuoteAttachment::where('quote_id', $quote->id)->get()->each(function ($a) {
            Storage::disk('public')->delete($a->file_path);
            Storage::disk('local')->delete($a->file_path);
        });
    }

    /** Webhook payload logs can hold customer PII - drop the payloads of privacy topics. */
    protected function scrubWebhookLogs(string $shopDomain): void
    {
        WebhookLog::where('shop', $shopDomain)
            ->whereIn('topic', ['customers/data_request', 'customers/redact'])
            ->delete();
    }
}
