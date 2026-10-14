<?php

namespace App\Services;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class QuotePdfService
{
    /**
     * Renders the quote to PDF and saves it under
     * storage/app/public/quotes/{shop_id}/{quote_number}.pdf
     * Returns the saved (relative) storage path.
     */
    public function generate(Quote $quote): string
    {
        // $quote->loadMissing(['items', 'customer', 'shop.settings']);

        // $settings = $quote->shop->settings;

        $pdf = Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'storeName' => $quote->shop,
            'storeAddress' => null, // wire up from shop settings if you store one
            // 'primaryColor' => $settings?->email_primary_color ?? '#1C1917',
            'primaryColor' => '#1C1917',
        ])->setPaper('a4');

        $path = "quotes/{$quote->shop_id}/{$quote->quote_number}.pdf";

        Storage::disk('public')->put($path, $pdf->output());

        $quote->update(['pdf_path' => $path]);

        return $path;
    }

    /**
     * Returns the public URL for an already-generated PDF, generating it
     * first if it doesn't exist yet.
     */
    public function getUrl(Quote $quote): string
    {
        if (! $quote->pdf_path || ! Storage::disk('public')->exists($quote->pdf_path)) {
            $this->generate($quote);
            $quote->refresh();
        }

        return Storage::disk('public')->url($quote->pdf_path);
    }
}
