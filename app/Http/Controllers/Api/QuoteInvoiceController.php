<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\Shop;
use App\Services\InvoicePdfService;
use App\Services\QuoteMailService;
use Illuminate\Http\Request;

/**
 * Invoice PDF - draft order bani gaya pachhi j (quote "converted") download / email thai shake.
 *
 *   GET  /api/quotes/{quote}/invoice-pdf      ?inline=1 (browser ma kholo) &lang=English
 *   POST /api/quotes/{quote}/send-invoice     { message?: string }  -> customer ne PDF attach sathe email
 */
class QuoteInvoiceController extends Controller
{
    public function download(Request $request, Quote $quote, InvoicePdfService $invoices)
    {
        $shop = $this->authorizeQuote($request, $quote);
        $this->ensureInvoiceable($quote);

        $pdf = $invoices->render($shop, $quote, $request->query('lang'));

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'invoice_downloaded',
            'performed_by_type' => 'staff',
            'description' => "Invoice {$quote->invoice_number} was exported as PDF",
        ]);

        $filename = $invoices->filename($quote);

        return $request->boolean('inline')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    public function send(Request $request, Quote $quote, InvoicePdfService $invoices, QuoteMailService $mailService)
    {
        $shop = $this->authorizeQuote($request, $quote);
        $this->ensureInvoiceable($quote);

        $data = $request->validate(['message' => ['nullable', 'string', 'max:2000']]);

        $quote->load(['customer', 'items', 'assignedStaff']);
        $email = $quote->customer->email ?? null;
        abort_if(! $email, 422, "This quote's customer has no email address.");

        // PDF hamesha taaji (saved data thi) banavo
        $pdf = $invoices->render($shop, $quote);
        $pdfAttachment = [$pdf->output(), $invoices->filename($quote)];

        $message = trim((string) ($data['message'] ?? ''));
        $paymentSection = filled($quote->checkout_url)
            ? '<p><a href="' . e($quote->checkout_url) . '">Pay securely online</a></p>'
            : '';

        $log = $mailService->send(
            $shop,
            QuoteMailService::TYPE_INVOICE_SENT,
            $email,
            [
                'invoice_number' => (string) $quote->invoice_number,
                'custom_message' => $message !== '' ? nl2br(e($message)) : '',
                'payment_link' => (string) $quote->checkout_url,
                'payment_section' => $paymentSection,
            ],
            $quote,
            $pdfAttachment
        );

        abort_if($log->status !== 'sent', 502, 'Failed to send email: ' . ($log->error_message ?: 'unknown error'));

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'invoice_sent',
            'performed_by_type' => 'staff',
            'description' => "Invoice {$quote->invoice_number} emailed to {$email}",
            'meta' => ['to' => $email, 'invoice_number' => $quote->invoice_number],
        ]);

        return response()->json([
            'message' => "Invoice {$quote->invoice_number} sent to {$email}.",
            'to' => $email,
            'invoice_number' => $quote->invoice_number,
        ]);
    }

    private function authorizeQuote(Request $request, Quote $quote): Shop
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');
        abort_unless($quote->shop_id === $shop->id, 403, 'This quote does not belong to your store.');

        return $shop;
    }

    /** Invoice fakt draft order bani gaya pachhi (cancelled / rejected / expired quote no nahi). */
    private function ensureInvoiceable(Quote $quote): void
    {
        abort_if(
            blank($quote->shopify_draft_order_gid) && blank($quote->shopify_draft_order_id),
            422,
            'Create a draft order first - the invoice is generated from the draft order.'
        );
        abort_if(
            in_array($quote->status, [Quote::STATUS_CANCELLED, Quote::STATUS_REJECTED, Quote::STATUS_EXPIRED], true),
            422,
            "This quote is {$quote->status}, so an invoice cannot be created."
        );
    }
}
