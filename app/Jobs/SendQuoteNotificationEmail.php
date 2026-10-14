<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Models\Shop;
use App\Services\QuoteMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendQuoteNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds between retries

    public function __construct(
        private int $shopId,
        private string $type,
        private string $recipientEmail,
        private array $placeholders = [],
        private ?int $quoteId = null,
    ) {}

    public function handle(QuoteMailService $mailService): void
    {
        $shop = Shop::findOrFail($this->shopId);
        $quote = $this->quoteId ? Quote::find($this->quoteId) : null;

        $mailService->send($shop, $this->type, $this->recipientEmail, $this->placeholders, $quote);
    }
}
