<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\QuotePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QuotePdfController extends Controller
{
    /**
     * GET /api/quotes/{quote}/pdf
     * Streams the PDF inline (opens in browser) - regenerates first if the
     * quote's items were edited more recently than the PDF was created.
     */
    public function show(Request $request, Quote $quote, QuotePdfService $pdfService)
    {
        abort_unless($quote->shop_id === $request->attributes->get('shop')->id, 403);

        if (! $quote->pdf_path
            || ! Storage::disk('public')->exists($quote->pdf_path)
            || $quote->updated_at->gt(Storage::disk('public')->lastModified($quote->pdf_path))
        ) {
            $pdfService->generate($quote);
            $quote->refresh();
        }

        return response(Storage::disk('public')->get($quote->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$quote->quote_number}.pdf\"",
        ]);
    }

    /**
     * POST /api/quotes/{quote}/pdf/regenerate
     * Force a fresh render - useful right after the merchant changes prices.
     */
    public function regenerate(Request $request, Quote $quote, QuotePdfService $pdfService)
    {
        abort_unless($quote->shop_id === $request->attributes->get('shop')->id, 403);

        $pdfService->generate($quote);

        return response()->json(['data' => ['pdf_url' => $pdfService->getUrl($quote->fresh())]]);
    }
}
