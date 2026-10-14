<?php

namespace App\Jobs;

use App\Services\GdprService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs a Shopify privacy request (customers/data_request, customers/redact,
 * shop/redact) after the webhook has already been acknowledged with 200.
 */
class ProcessGdprRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 60;

    public function __construct(
        public string $topic,
        public string $shopDomain,
        public array $payload,
    ) {
    }

    public function handle(GdprService $gdpr): void
    {
        match ($this->topic) {
            'customers/data_request' => $gdpr->exportCustomerData($this->shopDomain, $this->payload),
            'customers/redact' => $gdpr->redactCustomer($this->shopDomain, $this->payload),
            'shop/redact' => $gdpr->redactShop($this->shopDomain),
            default => Log::warning('Unknown GDPR topic', ['topic' => $this->topic]),
        };
    }

    public function failed(\Throwable $e): void
    {
        Log::error('GDPR request failed', [
            'topic' => $this->topic,
            'shop' => $this->shopDomain,
            'error' => $e->getMessage(),
        ]);
    }
}
