<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\Shop;
use App\Support\DefaultTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

/**
 * Quote (draft order bani gaya pachhi) mathi Invoice PDF banave chhe.
 *
 * Merchant e Settings > PDF Template > "Invoice PDF" ma je `invoice_pdf_template`
 * save kari hoy (style, show toggles, sections order, translation labels) te j vaapre chhe,
 * ane Blade view `pdf.invoice` render kare chhe.
 */
class InvoicePdfService
{
    public function filename(Quote $quote): string
    {
        $base = $quote->invoice_number ?: ($quote->quote_number ?? $quote->id);

        return 'invoice-' . trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $base), '-') . '.pdf';
    }

    /**
     * @return \Barryvdh\DomPDF\PDF
     */
    public function render(Shop $shop, Quote $quote, ?string $lang = null)
    {
        $quote->load(['customer', 'items', 'assignedStaff', 'fieldValues.formField']);

        // Invoice number pehli vaar j banse (format: Settings > Formatting standards), pachhi same rahe chhe
        $quote->assignInvoiceNumber($shop);

        $template = $this->resolveTemplate($shop);
        $labels = $this->resolveLabels($template, $lang);
        $style = $template['style'];
        $show = $template['show'];

        $visibleSections = collect($template['sections'])
            ->filter(fn ($s) => ! empty($s['visible']))
            ->pluck('id')
            ->values()
            ->all();

        // ---- totals: items + discount/shipping/tax thi taaji calculate ----
        $quote->recalculateTotals();
        $discount = (float) $quote->discount_amount;
        // "Display discount value on the Invoice PDF" tick na hoy to discount line chhupavo,
        // pan subtotal ma discount ghatadi nakho jethi PDF no hisab barabar dekhay.
        $discountVisible = $quote->discount_show_on_invoice_pdf && $discount > 0 && ! empty($show['total']['discount']);
        $totals = [
            'subtotal' => (float) $quote->subtotal,
            'subtotal_display' => $discountVisible ? (float) $quote->subtotal : round((float) $quote->subtotal - $discount, 2),
            'discount' => $discount,
            'discount_visible' => $discountVisible,
            'shipping' => (float) $quote->shipping_price,
            'tax' => (float) $quote->tax_amount,
            'grand' => (float) $quote->total,
        ];

        // ---- per product lines (product wise discount sathe) ----
        $lines = $quote->items->mapWithKeys(function ($item) {
            $qty = max((int) $item->quantity, 1);
            $unitBefore = (float) ($item->customer_requested_price ?? $item->original_price);
            $discountValue = (float) $item->discount_value;
            $hasDiscount = $item->discount_type && $discountValue > 0;
            $base = $unitBefore * $qty;
            $discountAmount = $hasDiscount
                ? round(min($item->discount_type === 'percentage' ? $base * min($discountValue, 100) / 100 : $discountValue, $base), 2)
                : 0.0;
            $lineTotal = round($base - $discountAmount, 2);

            return [$item->id => [
                'has_discount' => $discountAmount > 0,
                'unit_before' => $unitBefore,
                'unit_after' => round($lineTotal / $qty, 2),
                'discount_amount' => $discountAmount,
                'label' => $discountAmount > 0
                    ? ($item->discount_type === 'percentage'
                        ? rtrim(rtrim(number_format($discountValue, 2, '.', ''), '0'), '.') . '% OFF'
                        : 'OFF ' . number_format($discountAmount, 2))
                    : '',
                'line_total' => $lineTotal,
                'properties' => $this->normalizeProperties($item->properties),
            ]];
        })->all();

        // ---- dates ----
        $dateFormat = match ($show['header']['dateFormat'] ?? 'YYYY-MM-DD') {
            'DD/MM/YYYY' => 'd/m/Y',
            'MM/DD/YYYY' => 'm/d/Y',
            default => 'Y-m-d',
        };
        $issued = $quote->invoice_issued_at ?? now();
        $due = $quote->due_date;
        $dates = [
            'issued' => Carbon::parse($issued)->format($dateFormat),
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
        $customerAddress = $this->customerAddress($customer);

        $store = [
            'name' => (string) (data_get($shop, 'name') ?: data_get($shop, 'shop', '')),
            'email' => (string) data_get($shop, 'email', ''),
            'phone' => (string) data_get($shop, 'phone', ''),
            'address' => $this->formatAddress(data_get($shop, 'address')),
        ];
        $salesperson = [
            'name' => (string) data_get($quote, 'assignedStaff.name', ''),
            'email' => (string) data_get($quote, 'assignedStaff.email', ''),
            'phone' => (string) data_get($quote, 'assignedStaff.phone', ''),
        ];

        $inquiry = $quote->fieldValues
            ->map(fn ($fv) => [
                'label' => (string) ($fv->label ?: ($fv->formField->label ?? '')),
                'value' => (string) ($fv->value ?? ''),
            ])
            ->filter(fn ($r) => $r['label'] !== '' && $r['value'] !== '')
            ->values()
            ->all();

        return Pdf::setOption([
            'isRemoteEnabled' => true,        // product image URL (Shopify CDN) load karva mate
            'defaultFont' => 'DejaVu Sans',
        ])->loadView('pdf.invoice', [
            'quote' => $quote,
            'invoiceNumber' => $quote->invoice_number,
            'currency' => (string) $quote->currency,
            'labels' => $labels,
            'show' => $show,
            'sections' => $visibleSections,
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
            'totals' => $totals,
            'lines' => $lines,
            'dates' => $dates,
            'customerInfo' => $customerInfo,
            // Customer table ma alag shipping / billing address nathi, etle ek j address banne ma vaprayo chhe
            'shippingAddress' => $customerAddress,
            'billingAddress' => $customerAddress,
            'paymentTerms' => (string) data_get($quote, 'payment_terms', ''),
            'store' => $store,
            'salesperson' => $salesperson,
            'inquiry' => $inquiry,
            'draftNote' => (string) ($quote->merchant_note ?? ''),
            'customerNote' => (string) ($quote->customer_note ?? ''),
            'footerText' => (string) ($show['footer']['text'] ?? ''),
        ])->setPaper('a4');
    }

    /* ---------------- template ---------------- */

    public function resolveTemplate(Shop $shop): array
    {
        $extra = $shop->settings->extra_settings ?? [];
        if (is_string($extra)) {
            $extra = json_decode($extra, true) ?: [];
        }

        // Customized templates fakt "templates" feature vala plan (Pro) par lagu padse.
        $saved = $shop->canUse('templates') && is_array($extra['invoice_pdf_template'] ?? null)
            ? $extra['invoice_pdf_template']
            : [];

        $defaults = $this->defaultTemplate();
        $template = array_replace_recursive($defaults, $saved);

        // Sections: merchant e set kareli order rakho, navi default sections pachhi umero
        $savedSections = is_array($saved['sections'] ?? null) ? $saved['sections'] : [];
        if ($savedSections) {
            $known = array_column($savedSections, 'id');
            $template['sections'] = array_merge(
                $savedSections,
                array_values(array_filter($defaults['sections'], fn ($s) => ! in_array($s['id'], $known, true)))
            );
        }

        return $template;
    }

    public function defaultLabels(): array
    {
        return DefaultTemplates::invoicePdfLabels();
    }

    // Source: DefaultTemplates::invoicePdf() (frontend Invoicepdftemplateeditor.jsx na DEFAULT_TEMPLATE jevo j)
    public function defaultTemplate(): array
    {
        return DefaultTemplates::invoicePdf();
    }

    /** Auto detect ON hoy ane ?lang= match thay to e language, nahi to default language na labels. */
    private function resolveLabels(array $template, ?string $lang): array
    {
        $languages = collect($template['translation']['languages'] ?? []);

        $picked = null;
        if (! empty($template['translation']['autoDetect']) && $lang) {
            $picked = $languages->first(fn ($l) => strcasecmp($l['name'] ?? '', $lang) === 0);
        }
        $picked ??= $languages->firstWhere('isDefault', true) ?? $languages->first();

        return array_merge(
            $this->defaultLabels(),
            array_filter($picked['labels'] ?? [], fn ($v) => $v !== null && $v !== '')
        );
    }

    /* ---------------- helpers ---------------- */

    /** Item properties ["name" => "value"] ke [["name"=>..,"value"=>..]] bane format ne [label, value] ma fervi nakhe */
    private function normalizeProperties($properties): array
    {
        if (! is_array($properties)) {
            return [];
        }

        $rows = [];
        foreach ($properties as $key => $prop) {
            if (is_array($prop)) {
                $name = $prop['name'] ?? $prop['key'] ?? $prop['label'] ?? null;
                $value = $prop['value'] ?? null;
            } else {
                $name = is_string($key) ? $key : null;
                $value = $prop;
            }
            if (filled($name) && is_scalar($value) && (string) $value !== '') {
                $rows[] = [(string) $name, (string) $value];
            }
        }

        return $rows;
    }

    private function customerAddress($customer): string
    {
        if (! $customer) {
            return '';
        }

        return collect([$customer->address, $customer->city, $customer->state, $customer->zip, $customer->country])
            ->filter(fn ($v) => filled($v))
            ->implode(', ');
    }

    // DomPDF na built-in fonts ma map kare chhe
    private function pdfFont(string $name): string
    {
        return match ($name) {
            'Arial', 'Verdana' => 'Helvetica',
            'Georgia', 'Times New Roman' => 'Times',
            'Courier New' => 'Courier',
            default => 'DejaVu Sans',
        };
    }

    // CSS injection thi bachva mate fakt valid hex color
    private function safeColor(?string $color, string $fallback): string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : $fallback;
    }

    private function clampInt($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    // logo: fakt base64 image ke https URL
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
