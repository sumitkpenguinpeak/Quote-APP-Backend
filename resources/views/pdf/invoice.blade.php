@php
    // currency "$" jevo symbol hoy to chipki ne, "USD" jevo code hoy to space sathe
    $cur = trim($currency);
    $curPrefix = preg_match('/^[A-Za-z]{3}$/', $cur) ? strtoupper($cur) . ' ' : $cur;
    $money = fn ($n) => $curPrefix . number_format((float) $n, 2);
    $has = fn ($v) => $v !== null && trim((string) $v) !== '';
    $showSupplier = $show['supplier'] ?? [];
    $showCustomer = $show['customer'] ?? [];
    $showProduct = $show['product'] ?? [];
    $showTotal = $show['total'] ?? [];
    $showNote = $show['note'] ?? [];
    $showFooter = $show['footer'] ?? [];
    $showSales = $show['salesperson'] ?? [];
    $showHeader = $show['header'] ?? [];
    $tagline = (string) ($labels['invoice'] ?? 'Invoice');
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $tagline }} {{ $invoiceNumber }}</title>
<style>
    @page { margin: 34px 38px; }
    html, body { margin: 0; padding: 0; background: {{ $s['background'] }}; }
    body {
        font-family: {{ $fonts['content'] }}, 'DejaVu Sans', sans-serif;
        font-size: {{ $s['contentSize'] }}px;
        color: {{ $s['text'] }};
        line-height: 1.4;
    }
    h1, h2, h3, .head { font-family: {{ $fonts['header'] }}, 'DejaVu Sans', sans-serif; color: {{ $s['primary'] }}; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .muted { color: #6b7280; }
    .small { font-size: 0.82em; }
    .label { font-size: 0.78em; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 3px; }
    .title { font-size: {{ $s['headerSize'] }}px; font-weight: bold; text-transform: uppercase; margin: 0 0 6px 0; }
    .section { margin-top: 22px; }
    .right { text-align: right; }
    .center { text-align: center; }
    .items th {
        background: {{ $s['primary'] }}; color: #ffffff; text-align: left;
        padding: 7px 8px; font-size: 0.85em; text-transform: uppercase;
    }
    .items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
    .items .num { text-align: right; white-space: nowrap; }
    .items table.inner td { border: 0; padding: 0; }
    .strike { text-decoration: line-through; color: #9ca3af; }
    .badge { color: {{ $s['primary'] }}; font-size: 0.8em; font-weight: bold; }
    .totals td { padding: 4px 0; }
    .totals .grand td { border-top: 2px solid {{ $s['primary'] }}; padding-top: 8px; font-weight: bold; font-size: 1.1em; }
    .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb; text-align: center; }
    img.logo { max-width: 100%; }
</style>
</head>
<body>

{{-- ================= HEADER ================= --}}
<table>
    <tr>
        <td style="width: 55%;">
            @if ($s['showLogo'] && $logo !== '')
                <img class="logo" src="{{ $logo }}" style="width: {{ $s['logoSize'] }}px;" alt="">
            @elseif (! empty($showSupplier['name']) && $has($store['name']))
                <div class="head" style="font-size: 1.3em; font-weight: bold;">{{ $store['name'] }}</div>
            @endif
        </td>
        <td class="right" style="width: 45%;">
            <div class="title head">{{ $tagline }}</div>
            @if (! empty($showHeader['orderNumber']))
                <div><span class="muted">{{ $labels['orderNumber'] }}:</span> <strong>{{ $invoiceNumber }}</strong></div>
            @endif
            @if (! empty($showHeader['issueDate']))
                <div><span class="muted">{{ $labels['issueDate'] }}:</span> {{ $dates['issued'] }}</div>
            @endif
            @if ($dates['due'])
                <div><span class="muted">{{ $labels['dueDate'] }}:</span> {{ $dates['due'] }}</div>
            @endif
            @if (! empty($showHeader['orderAmount']))
                <div><span class="muted">{{ $labels['orderAmount'] }}:</span> <strong>{{ $money($totals['grand']) }}</strong></div>
            @endif
        </td>
    </tr>
</table>

{{-- ================= SECTIONS (merchant e set kareli order mujab) ================= --}}
@foreach ($sections as $sectionId)

    @if ($sectionId === 'parties')
        <table class="section">
            <tr>
                <td style="width: 50%; padding-right: 12px;">
                    <div class="label">{{ $labels['invoiceFrom'] }}</div>
                    @if (! empty($showSupplier['name']) && $has($store['name']))<div><strong>{{ $store['name'] }}</strong></div>@endif
                    @if (! empty($showSupplier['email']) && $has($store['email']))<div>{{ $store['email'] }}</div>@endif
                    @if (! empty($showSupplier['phone']) && $has($store['phone']))<div>{{ $store['phone'] }}</div>@endif
                    @if (! empty($showSupplier['address']) && $has($store['address']))<div>{{ $store['address'] }}</div>@endif
                </td>
                <td style="width: 50%; padding-left: 12px;">
                    <div class="label">{{ $labels['invoiceTo'] }}</div>
                    @if (! empty($showCustomer['name']) && $has($customerInfo['name']))<div><strong>{{ $customerInfo['name'] }}</strong></div>@endif
                    @if (! empty($showCustomer['company']) && $has($customerInfo['company']))<div>{{ $customerInfo['company'] }}</div>@endif
                    @if (! empty($showCustomer['email']) && $has($customerInfo['email']))<div>{{ $customerInfo['email'] }}</div>@endif
                    @if (! empty($showCustomer['phone']) && $has($customerInfo['phone']))<div>{{ $customerInfo['phone'] }}</div>@endif
                </td>
            </tr>
        </table>
    @endif

    @if ($sectionId === 'addresses')
        @php
            $showShip = ! empty($showCustomer['shipping']) && $has($shippingAddress);
            $showBill = ! empty($showCustomer['billing']) && $has($billingAddress);
        @endphp
        @if ($showShip || $showBill)
            <table class="section">
                <tr>
                    <td style="width: 50%; padding-right: 12px;">
                        @if ($showShip)
                            <div class="label">{{ $labels['shippingAddress'] }}</div>
                            <div>{{ $shippingAddress }}</div>
                        @endif
                    </td>
                    <td style="width: 50%; padding-left: 12px;">
                        @if ($showBill)
                            <div class="label">{{ $labels['billingAddress'] }}</div>
                            <div>{{ $billingAddress }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        @endif
    @endif

    @if ($sectionId === 'extra')
        @php
            $showTerms = ! empty($showCustomer['paymentTerms']) && $has($paymentTerms);
            $showSalesperson = ! empty($showSales['show']) && $has($salesperson['name']);
            $showInquiry = ! empty($showCustomer['inquiry']) && count($inquiry) > 0;
        @endphp
        @if ($showTerms || $showSalesperson || $showInquiry)
            <table class="section">
                <tr>
                    @if ($showTerms)
                        <td style="padding-right: 12px;">
                            <div class="label">{{ $labels['paymentTerms'] }}</div>
                            <div>{{ $paymentTerms }}</div>
                        </td>
                    @endif
                    @if ($showSalesperson)
                        <td style="padding-right: 12px;">
                            <div class="label">{{ $labels['salesperson'] }}</div>
                            <div>{{ $salesperson['name'] }}</div>
                            @if (! empty($showSales['email']) && $has($salesperson['email']))<div class="small muted">{{ $salesperson['email'] }}</div>@endif
                            @if (! empty($showSales['phone']) && $has($salesperson['phone']))<div class="small muted">{{ $salesperson['phone'] }}</div>@endif
                        </td>
                    @endif
                    @if ($showInquiry)
                        <td>
                            <div class="label">{{ $labels['inquiry'] }}</div>
                            @foreach ($inquiry as $row)
                                <div class="small"><span class="muted">{{ $row['label'] }}:</span> {{ $row['value'] }}</div>
                            @endforeach
                        </td>
                    @endif
                </tr>
            </table>
        @endif
    @endif

    @if ($sectionId === 'products')
        <table class="section items">
            <thead>
                <tr>
                    <th>{{ $labels['item'] }}</th>
                    <th class="num" style="width: 9%; text-align: right;">{{ $labels['qty'] }}</th>
                    <th class="num" style="width: 20%; text-align: right;">{{ $labels['price'] }}</th>
                    <th class="num" style="width: 20%; text-align: right;">{{ $labels['total'] }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quote->items as $item)
                    @php $line = $lines[$item->id] ?? null; @endphp
                    <tr>
                        <td>
                            <table class="inner">
                                <tr>
                                    @if (! empty($showProduct['image']) && $has($item->image_url))
                                        <td style="width: 46px; padding-right: 8px;">
                                            <img src="{{ $item->image_url }}" style="width: 40px; height: 40px;" alt="">
                                        </td>
                                    @endif
                                    <td>
                                        <div><strong>{{ $item->product_title }}</strong></div>
                                        @if (! empty($showProduct['variant']) && $has($item->variant_title) && $item->variant_title !== 'Default Title')
                                            <div class="small muted">{{ $item->variant_title }}</div>
                                        @endif
                                        @if (! empty($showProduct['sku']) && $has($item->sku))
                                            <div class="small muted">SKU: {{ $item->sku }}</div>
                                        @endif
                                        @if (! empty($showProduct['description']) && $has($item->note))
                                            <div class="small">{{ $item->note }}</div>
                                        @endif
                                        @if ($line)
                                            @foreach ($line['properties'] as [$pName, $pValue])
                                                <div class="small muted">{{ $pName }}: {{ $pValue }}</div>
                                            @endforeach
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td class="num">{{ $item->quantity }}</td>
                        <td class="num">
                            @if ($line && $line['has_discount'] && ! empty($showProduct['discount']))
                                <div class="strike">{{ $money($line['unit_before']) }}</div>
                                <div>{{ $money($line['unit_after']) }}</div>
                                <div class="badge">{{ $line['label'] }}</div>
                            @else
                                {{ $money($line ? $line['unit_after'] : $item->original_price) }}
                            @endif
                        </td>
                        <td class="num">{{ $money($line ? $line['line_total'] : $item->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($sectionId === 'summary')
        @php
            $showDraftNote = ! empty($showNote['draftOrder']) && $has($draftNote);
            $showCustNote = ! empty($showNote['customer']) && $has($customerNote);
            $taxLabel = $has($quote->tax_name) ? $quote->tax_name : $labels['tax'];
            if ($quote->tax_type === 'percentage' && (float) $quote->tax_value > 0) {
                $taxLabel .= ' (' . rtrim(rtrim(number_format((float) $quote->tax_value, 2, '.', ''), '0'), '.') . '%)';
            }
        @endphp
        <table class="section">
            <tr>
                <td style="width: 52%; padding-right: 16px;">
                    @if ($showDraftNote || $showCustNote)
                        <div class="label">{{ $labels['note'] }}</div>
                        @if ($showDraftNote)<div>{!! nl2br(e($draftNote)) !!}</div>@endif
                        @if ($showCustNote)<div class="muted" style="margin-top: 6px;">{!! nl2br(e($customerNote)) !!}</div>@endif
                    @endif
                </td>
                <td style="width: 48%;">
                    <table class="totals">
                        @if (! empty($showTotal['subtotal']))
                            <tr>
                                <td>{{ $labels['subtotal'] }}</td>
                                <td class="right">{{ $money($totals['subtotal_display']) }}</td>
                            </tr>
                        @endif
                        @if ($totals['discount_visible'])
                            <tr>
                                <td>{{ $labels['discount'] }}@if ($has($quote->discount_reason)) <span class="small muted">({{ $quote->discount_reason }})</span>@endif</td>
                                <td class="right">-{{ $money($totals['discount']) }}</td>
                            </tr>
                        @endif
                        @if (! empty($showTotal['shipping']) && $totals['shipping'] > 0)
                            <tr>
                                <td>{{ $labels['shipping'] }}@if ($has($quote->shipping_name)) <span class="small muted">({{ $quote->shipping_name }})</span>@endif</td>
                                <td class="right">{{ $money($totals['shipping']) }}</td>
                            </tr>
                        @endif
                        @if (! empty($showTotal['tax']) && $totals['tax'] > 0)
                            <tr>
                                <td>{{ $taxLabel }}</td>
                                <td class="right">{{ $money($totals['tax']) }}</td>
                            </tr>
                        @endif
                        @if (! empty($showTotal['grandTotal']))
                            <tr class="grand">
                                <td>{{ $labels['grandTotal'] }}</td>
                                <td class="right">{{ $money($totals['grand']) }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if ($sectionId === 'footer' && ! empty($showFooter['show']))
        <div class="footer small muted">
            @if ($has($footerText))<div>{!! nl2br(e($footerText)) !!}</div>@endif
            @if (! empty($showFooter['storeName']) && $has($store['name']))<div style="margin-top: 6px;"><strong>{{ $store['name'] }}</strong></div>@endif
            @if (! empty($showFooter['email']) && $has($store['email']))<div>{{ $store['email'] }}</div>@endif
            @if (! empty($showFooter['address']) && $has($store['address']))<div>{{ $store['address'] }}</div>@endif
        </div>
    @endif

@endforeach

</body>
</html>
