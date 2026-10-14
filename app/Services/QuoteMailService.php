<?php

namespace App\Services;

use App\Mail\DynamicTemplateMail;
use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Models\Quote;
use App\Models\Shop;
use App\Support\DefaultTemplates;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class QuoteMailService
{
    public function __construct(private QuotePdfService $pdfService, private GmailService $gmail) {}

    // Matches the `type` column values used across email_templates / notifications_log
    public const TYPE_NEW_QUOTE_TO_MERCHANT = 'new_quote_to_merchant';
    public const TYPE_QUOTE_RECEIVED_TO_CUSTOMER = 'quote_received_to_customer';
    public const TYPE_QUOTE_APPROVED = 'quote_approved';
    public const TYPE_QUOTE_REJECTED = 'quote_rejected';
    public const TYPE_NEW_MESSAGE = 'new_message';
    public const TYPE_QUOTE_CONVERTED = 'quote_converted_to_order';
    public const TYPE_QUOTE_EXPIRING_SOON = 'quote_expiring_soon';
    public const TYPE_QUOTE_PROPOSAL = 'quote_proposal';
    public const TYPE_QUOTE_UPDATED = 'quote_updated';
    public const TYPE_QUOTE_CANCELLED = 'quote_cancelled';
    public const TYPE_INVOICE_SENT = 'invoice_sent';
    
    // Email types that should carry the quote PDF as an attachment.
    private const PDF_ATTACHED_TYPES = [
        self::TYPE_QUOTE_RECEIVED_TO_CUSTOMER,
        self::TYPE_QUOTE_APPROVED,
        self::TYPE_QUOTE_CONVERTED,
        self::TYPE_NEW_QUOTE_TO_MERCHANT,
        self::TYPE_QUOTE_PROPOSAL,
    ];

    /**
     * Sends a notification email for the given quote, using whatever
     * template the merchant has configured (or the built-in default if
     * they haven't customized one yet).
     *
     * @param  array<string, string>  $placeholders  Extra {{tokens}} beyond the auto-filled ones.
     * @param  array{0: string, 1: string}|null  $pdfOverride  [pdf bytes, filename] - aapo to cached PDF ni jagyae aa attach thase.
     * @return NotificationLog  status 'sent' ke 'failed' (error_message sathe)
     */
    public function send(Shop $shop, string $type, string $recipientEmail, array $placeholders = [], ?Quote $quote = null, ?array $pdfOverride = null): NotificationLog
    {

        // echo "----,,---- type", $type; die;

        $template = EmailTemplate::where('shop_id', $shop->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->first(); 

        [$subject, $body] = $template
            ? [$template->subject, $template->body_html]
            : $this->defaultTemplate($type);

        $isFullDocument = $template?->is_full_document ?? false;

        $tokens = array_merge($this->autoTokens($shop, $quote), $placeholders);

        $renderedSubject = $this->replaceTokens($subject, $tokens);
        $renderedBody = $this->replaceTokens($body, $tokens);

        // $settings = $shop->settings;

        $log = NotificationLog::create([
            'shop_id' => $shop->id,
            'quote_id' => $quote?->id,
            'channel' => 'email',
            'type' => $type,
            'recipient' => $recipientEmail,
            'subject' => $renderedSubject,
            'status' => 'pending',
        ]);

        [$pdfContents, $pdfFilename] = $pdfOverride
            ?: ($quote && in_array($type, self::PDF_ATTACHED_TYPES, true)
                ? $this->resolvePdfAttachment($quote)
                : [null, null]);

        try {
            $mailable = new DynamicTemplateMail(
                renderedSubject: $renderedSubject,
                renderedHtmlBody: $renderedBody,
                storeName: $shop->shop,
                logoUrl: '',//$settings?->email_logo_url,
                // primaryColor: $settings?->email_primary_color ?? '#1C1917',
                primaryColor: '#1C1917',
                footerText: '',
                quoteNumber: $quote?->quote_number,
                actionUrl: $quote ? $this->buildActionUrl($shop, $quote, $type) : null,
                actionLabel: $quote ? $this->actionLabelForType($type) : null,
                isFullDocument: $isFullDocument,
                pdfContents: $pdfContents, 
                pdfFilename: $pdfFilename,
            );

            // Send from the merchant's own Gmail when they connected one
            // (Settings > Email Configuration); otherwise use the default mailer.
            $account = $shop->emailAccount;

            if ($account && $account->isActive()) {
                $this->gmail->sendTemplateMail($account, $recipientEmail, $mailable, $shop->shop);
            } else {
                Mail::to($recipientEmail)->send($mailable);
            }

            $log->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            report($e);
        }

        return $log->fresh();
    }

     /**
     * Makes sure the quote's PDF exists (generating it if missing or stale),
     * then reads its bytes so they can be attached without relying on a
     * public filesystem path (works with local, S3, etc. alike).
     *
     * @return array{0: string|null, 1: string|null}  [contents, filename]
     */
    private function resolvePdfAttachment(Quote $quote): array
    {
        try {
            if (! $quote->pdf_path || ! Storage::disk('public')->exists($quote->pdf_path)) {
                $this->pdfService->generate($quote);
                $quote->refresh();
            }

            $contents = Storage::disk('public')->get($quote->pdf_path);

            return [$contents, "{$quote->quote_number}.pdf"];
        } catch (Throwable $e) {
            // Don't let a PDF failure block the email itself from sending.
            report($e);

            return [null, null];
        }
    }

    /**
     * Where the email's call-to-action button should point. Merchant-facing
     * types link into the admin panel, customer-facing types could instead
     * link to a storefront "track my quote" page once you build one.
     */
    private function buildActionUrl(Shop $shop, Quote $quote, string $type): ?string
    {
        $merchantTypes = [self::TYPE_NEW_QUOTE_TO_MERCHANT, self::TYPE_NEW_MESSAGE];

        if (in_array($type, $merchantTypes, true)) {
            return rtrim(config('app.url'), '/') . "/dashboard?shop={$shop->shopify_domain}#/quotes/{$quote->id}";
        }

        return null;
    }

    private function actionLabelForType(string $type): ?string
    {
        return match ($type) {
            self::TYPE_NEW_QUOTE_TO_MERCHANT => 'View quote request',
            self::TYPE_NEW_MESSAGE => 'View conversation',
            default => null,
        };
    }

    /**
     * Tokens filled in automatically from the shop/quote, on top of
     * whatever custom placeholders the caller passes in.
     */
    private function autoTokens(Shop $shop, ?Quote $quote): array
    {
        $tokens = [
            'store_name' => $shop->shop,
            // 'store_domain' => $shop->shopify_domain,
             'current_date' => now()->format('d M Y'),
            'year' => now()->format('Y'),
        ];

           if (! $quote) {
            return $tokens;
        }

        $quote->loadMissing(['items', 'customer', 'assignedStaff', 'fieldValues']);

        // subtotal / discount / total ne items + discount fields thi taaji calculate kare chhe
        $quote->recalculateTotals();
        $subtotal = (float) $quote->subtotal;
        $discountAmount = (float) $quote->discount_amount;

        $tokens += [
            'quote_id' => $quote->quote_number,       // alias, some templates call it Quote_ID
            'quote_number' => $quote->quote_number,
            'quote_status' => ucfirst($quote->status),
            'quote_total' => $quote->currency . number_format((float) $quote->total, 2),
            'grand_total' => $quote->currency . number_format((float) $quote->total, 2),
            'subtotal' => $quote->currency . number_format($subtotal, 2),
            // "Display discount value on the Email" tick hoy to j discount batavo
            'discount' => ! $quote->discount_show_on_email
                ? ''
                : ($discountAmount > 0 ? '-' . $quote->currency . number_format($discountAmount, 2) : $quote->currency . '0.00'),
            'customer_name' => trim(($quote->customer->first_name ?? '') . ' ' . ($quote->customer->last_name ?? '')),
            'prepared_for' => trim(($quote->customer->first_name ?? '') . ' ' . ($quote->customer->last_name ?? '')),
            'customer_email' => $quote->customer->email ?? '',
            'consultant_name' => $quote->assignedStaff->name ?? '',
            'quote_items_count' => (string) $quote->items->count(),
            'expiry_date' => $quote->expires_at?->format('d M Y') ?? '',
            'request_info_link' => rtrim(config('app.url'), '/') . "/quotes/{$quote->id}/reply",
        ];

        // Per-line-item tokens: {{Product_1}}, {{Description_1}}, {{Qty_1}},
        // {{Price_1}}, {{Total_1}} ... up to 5 rows. Blank out unused rows so
        // a template built for 5 products still looks clean with fewer items.
        foreach (range(1, 5) as $i) {
            $item = $quote->items->get($i - 1);

            $tokens["product_{$i}"] = $item?->product_title ?? '';
            $tokens["description_{$i}"] = $item?->variant_title ?? '';
            $tokens["qty_{$i}"] = $item ? (string) $item->quantity : '';
            $tokens["price_{$i}"] = $item ? $quote->currency . number_format((float) ($item->final_price ?? $item->merchant_offered_price ?? $item->original_price), 2) : '';
            $tokens["total_{$i}"] = $item ? $quote->currency . number_format((float) $item->line_total, 2) : '';
        }

        // Any custom quote form field (Country, Service Type, etc.) becomes a
        // token automatically using its `key` (machine name) - so a merchant
        // who adds a "Country" field with key "country" can use {{Country}}.
        foreach ($quote->fieldValues as $fieldValue) {
            $key = $fieldValue->formField?->key;
            if ($key) {
                $tokens[strtolower($key)] = $fieldValue->value ?? '';
            }
        }

        return $tokens;
    }

    /**
     * Replaces {{token}} placeholders in a template string with values.
     * Matching is case-insensitive ({{Country}} and {{country}} both work),
     * and any token without a matching value is left blank rather than shown raw.
     */
    private function replaceTokens(string $text, array $tokens): string
    {
          $lowercaseTokens = array_change_key_case($tokens, CASE_LOWER);

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($lowercaseTokens) {
            return $lowercaseTokens[strtolower($matches[1])] ?? '';
        }, $text);
    }

    /**
     * Fallback template (jyare DB ma row na hoy, e.g. merchant e "Revert to default" karyu hoy).
     * Install vakhate aa j defaults DB ma save thay chhe - source: DefaultTemplates::emails().
     */
    private function defaultTemplate(string $type): array
    {
        return DefaultTemplates::email($type);
    }
}