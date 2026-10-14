<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Services\QuotePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateQuotePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $quoteId) {}

    public function handle(QuotePdfService $pdfService): void
    {
        $quote = Quote::find($this->quoteId);

        if (! $quote) {
            return;
        }

        $pdfService->generate($quote);
    }
}
