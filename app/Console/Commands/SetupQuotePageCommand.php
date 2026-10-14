<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyPageService;
use Illuminate\Console\Command;

/**
 * /pages/request-a-quote page + theme template manually banavo (already install thayela shops mate / debug mate).
 *
 *   php artisan quote:setup-page                      # badha active shops
 *   php artisan quote:setup-page my-store.myshopify.com
 */
class SetupQuotePageCommand extends Command
{
    protected $signature = 'quote:setup-page {shop? : e.g. my-store.myshopify.com (khali = badha active shops)}';

    protected $description = 'Create the /pages/request-a-quote page and its theme template';

    public function handle(ShopifyPageService $pages): int
    {
        $shops = Shop::active()
            ->when($this->argument('shop'), fn ($q, $domain) => $q->where('shop', strtolower($domain)))
            ->get();

        if ($shops->isEmpty()) {
            $this->error('No matching active shop found.');

            return self::FAILURE;
        }

        foreach ($shops as $shop) {
            try {
                $result = $pages->setup($shop);
                $this->line(sprintf(
                    '%s  template=%s  page=%s',
                    $shop->shop,
                    $result['template'] ? 'ok' : 'FAILED (see laravel.log)',
                    $result['page_id'] ?: 'FAILED (see laravel.log)'
                ));
            } catch (\Throwable $e) {
                $this->error("{$shop->shop}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
