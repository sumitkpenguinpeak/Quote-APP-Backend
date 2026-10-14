<?php

namespace App\Jobs;

use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\Shop;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateQuotePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $quoteId,
        public ?string $lang = null,
    ) {}

    public function handle(): void
    {
        $quote = Quote::with(['customer', 'items', 'assignedStaff'])->findOrFail($this->quoteId);
        $shop_name = Shop::findOrFail($quote->shop_id)->shop;
        $shop = Shop::where('shop', $shop_name)->first();
        $settings = $shop->settings ?? null;
        $extra = $settings->extra_settings ?? [];
        $template = $this->resolveQuotePdfTemplate($shop);
        $labels = $this->resolvePdfLabels($template, $this->lang);

        $style = $template['style'];
        $content = $template['content'];

        // ---- totals ----
        // Items + discount/shipping/tax thi taaji calculate (Quote::recalculateTotals) - save kareli value sathe j match thay
        $quote->recalculateTotals();
        $totals = [
            'subtotal' => (float) $quote->subtotal,
            'discount' => (float) $quote->discount_amount,
            'shipping' => (float) $quote->shipping_price,
            'tax' => (float) $quote->tax_amount,
            'grand' => (float) $quote->total,
        ];

        // ---- dates ----
        $dateFormat = match ($content['quotation']['dateFormat'] ?? 'MM/DD/YYYY') {
            'DD/MM/YYYY' => 'd/m/Y',
            'YYYY-MM-DD' => 'Y-m-d',
            default => 'm/d/Y',
        };
        $due = data_get($quote, 'due_date');
        $dates = [
            'created' => optional($quote->created_at)->format($dateFormat),
            'due' => $due ? Carbon::parse($due)->format($dateFormat) : null,
        ];

        // ---- customer / store / salesperson ----
        $customer = $quote->customer;
        $customerInfo = [
            'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
            'company' => $customer->company_name ?? '',
            'email' => $customer->email ?? '',
            'phone' => $customer->phone ?? '',
        ];
        $store = [
            'name' => data_get($shop, 'name') ?: data_get($shop, 'shop_domain', ''),
            'phone' => data_get($shop, 'phone', ''),
            'address' => $this->formatAddress(data_get($shop, 'address')),
        ];
        $salesperson = [
            'name' => data_get($quote, 'assignedStaff.name', ''),
            'email' => data_get($quote, 'assignedStaff.email', ''),
            'phone' => data_get($quote, 'assignedStaff.phone', ''),
        ];

        $pdf = Pdf::setOption([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ])->loadView('pdf.quote', [
            'quote' => $quote,
            'template' => $template,
            'labels' => $labels,
            's' => [
                'showLogo' => (bool) ($style['showLogo'] ?? true),
                'logoSize' => $this->clampInt($style['logoSize'] ?? 80, 40, 200),
                'headerSize' => $this->clampInt($style['headerSize'] ?? 22, 8, 60),
                'contentSize' => $this->clampInt($style['contentSize'] ?? 14, 8, 30),
                'primary' => $this->safeColor($style['primary'] ?? null, '#161616'),
                'background' => $this->safeColor($style['background'] ?? null, '#FFFFFF'),
                'text' => $this->safeColor($style['text'] ?? null, '#161616'),
            ],
            'fonts' => [
                'header' => $this->pdfFont($style['headerFont'] ?? 'Default'),
                'content' => $this->pdfFont($style['contentFont'] ?? 'Default'),
            ],
            'logo' => $this->safeLogo($style['logo'] ?? ''),
            'c' => $content,
            'totals' => $totals,
            'dates' => $dates,
            'customerInfo' => $customerInfo,
            'store' => $store,
            'salesperson' => $salesperson,
            'shippingAddress' => $this->formatAddress(data_get($quote, 'shipping_address')),
            'billingAddress' => $this->formatAddress(data_get($quote, 'billing_address')),
            'paymentTerms' => data_get($quote, 'payment_terms', ''),
            'note' => data_get($quote, 'merchant_note', ''),
            'storeName'=> data_get($shop, 'name') ?: data_get($shop, 'shop_domain', ''),
            'storeAddress' => $this->formatAddress(data_get($shop, 'address')),
        ])->setPaper('a4');

        // ---- storage/app/public/quotes/quote-XXXX.pdf ma save ----
        $path = 'quotes/quote-' . Str::slug((string) ($quote->quote_number ?? $quote->id)) . '.pdf';
        $quotePDFFormate = $extra['quoteFormat'];
        $quoteFormate = str_replace(
            ['{{create_date}}', '{{quote_id}}'],
            [
                \Carbon\Carbon::parse($dates['created'])->format('Y-m-d'),
                $quote->id
            ],
            $quotePDFFormate
        );
        $path = $quoteFormate? $quoteFormate. '.pdf' : $path;
        
        Storage::disk('public')->put($path, $pdf->output());

        $quote->update(['pdf_path' => $path]);

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'pdf_generated',
            'performed_by_type' => 'system',
            'description' => 'Quote PDF was generated',
        ]);
    }

    /* ===================== helpers (controller mathi move karya) ===================== */

    private function resolveQuotePdfTemplate(Shop $shop): array
    {
        $settings = $shop->settings ?? null;
        $extra = $settings->extra_settings ?? [];
        if (is_string($extra)) {
            $extra = json_decode($extra, true) ?: [];
        }
        // Customized templates only apply on plans that include "templates" (Pro).
        $saved = $shop->canUse('templates') && is_array($extra['quote_pdf_template'] ?? null)
            ? $extra['quote_pdf_template']
            : [];

        return array_replace_recursive($this->defaultQuotePdfTemplate(), $saved);
    }

    // Source: DefaultTemplates::quotePdf() (pehla badha toggle false / labels khali hata, etle template save na hoy to PDF blank aavtu)
    private function defaultQuotePdfTemplate(): array
    {
        return \App\Support\DefaultTemplates::quotePdf();
    }

    private function resolvePdfLabels(array $template, ?string $lang): array
    {
        $fallback = $this->defaultQuotePdfTemplate()['translation']['languages'][0]['labels'];
        $languages = collect($template['translation']['languages'] ?? []);

        $picked = null;
        if (! empty($template['translation']['autoDetect']) && $lang) {
            $picked = $languages->first(fn ($l) => strcasecmp($l['name'] ?? '', $lang) === 0);
        }
        $picked ??= $languages->firstWhere('isDefault', true) ?? $languages->first();

        return array_merge($fallback, array_filter($picked['labels'] ?? [], fn ($v) => $v !== null && $v !== ''));
    }

    private function pdfFont(string $name): string
    {
        return match ($name) {
            'Arial' => 'Helvetica',
            'Georgia', 'Times New Roman' => 'Times',
            'Courier New' => 'Courier',
            default => 'DejaVu Sans',
        };
    }

    private function safeColor(?string $color, string $fallback): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : $fallback;
    }

    private function clampInt($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function safeLogo(?string $logo): string
    {
        return is_string($logo) && preg_match('#^(data:image/(png|jpe?g|gif);base64,|https://)#i', $logo) ? $logo : '';
    }

    private function formatAddress($address): string
    {
        if (is_string($address)) {
            $decoded = json_decode($address, true);
            $address = is_array($decoded) ? $decoded : $address;
        }
        if (is_array($address)) {
            return collect($address)->filter(fn ($v) => is_scalar($v) && $v !== '')->implode(', ');
        }

        return (string) ($address ?? '');
    }
}