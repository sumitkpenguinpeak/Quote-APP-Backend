<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyPageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SetupQuotePage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $shopId)
    {
    }

    public function handle(ShopifyPageService $pages): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop || ! $shop->is_active) {
            return;
        }

        $result = $pages->setup($shop);
        Log::info('Quote page setup done', ['shop' => $shop->shop] + $result);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Quote page setup failed', ['shop_id' => $this->shopId, 'error' => $e->getMessage()]);
    }
}
