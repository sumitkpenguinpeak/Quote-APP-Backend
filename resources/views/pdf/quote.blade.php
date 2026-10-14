{{-- resources/views/pdf/quote.blade.php --}}
@php
    $q = $c['quotation'];
    $cu = $c['customer'];
    $p = $c['product'];
    $st = $c['store'];
    $money = fn ($n) => '$' . number_format((float) $n, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quote {{ $quote->quote_number }}</title>
    <style>
        @page { padding: 0; margin: 0;}
        body {
            margin: 0;
            font-family: "{{ $fonts['content'] }}", "DejaVu Sans", sans-serif;
            font-size: {{ $s['contentSize'] }}px;
            color: {{ $s['text'] }};
            background: {{ $s['background'] }};
            padding: 28px;
        }
        h1 {
            margin: 0 0 6px;
            font-family: "{{ $fonts['header'] }}", "DejaVu Sans", sans-serif;
            font-size: {{ $s['headerSize'] * 1.5 }}px;
            color: {{ $s['primary'] }};
        }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .label { font-weight: bold; color: {{ $s['primary'] }}; margin-bottom: 3px; }
        .parties td { width: 33.33%; padding: 0 12px 16px 0; }
        .items th {
            text-align: left; padding: 8px;
            border-top: 1px solid {{ $s['primary'] }};
            border-bottom: 1px solid {{ $s['primary'] }};
            color: {{ $s['primary'] }};
        }
        .items td { padding: 8px; border-bottom: 1px solid #e4e5e7; }
        .items .inner td { padding: 0 8px 0 0; border: 0; }
        .right { text-align: right; }
        .muted { color: #6d7175; }
        .strike { text-decoration: line-through; color: #8a8a8a; }
        .totals td { padding: 6px 8px; }
        .grand td { font-weight: bold; border-top: 1px solid {{ $s['primary'] }}; }
    </style>
</head>
<body>

    {{-- ===== Header: title + meta + logo ===== --}}
    <table>
        <tr>
            <td>
                <h1>{{ $labels['quotation'] }}</h1>
                @if ($q['number'])
                    <div>{{ $labels['quoteNo'] }} {{ $quote->quote_number }}</div>
                @endif
                @if ($q['createdDate'])
                    <div>{{ $labels['createdDate'] }} {{ $dates['created'] }}</div>
                @endif
                @if ($q['dueDate'] && $dates['due'])
                    <div>{{ $labels['dueDate'] }} {{ $dates['due'] }}</div>
                @endif
            </td>
            <td class="right" style="width: 220px;">
                @if ($s['showLogo'] && $logo)
                    <img src="{{ $logo }}" style="width: {{ $s['logoSize'] }}px;" alt="Logo">
                @endif
            </td>
        </tr>
    </table>

    <div style="height: 22px;"></div>

    {{-- ===== From / To / Ship to ===== --}}
    <table class="parties">
        <tr>
            <td>
                <div class="label">{{ $labels['quoteFrom'] }}:</div>
                @if ($st['name'] && $store['name'])<div>{{ $store['name'] }}</div>@endif
                @if ($st['phone'] && $store['phone'])<div>{{ $store['phone'] }}</div>@endif
                @if ($st['address'] && $store['address'])<div>{{ $store['address'] }}</div>@endif
            </td>
            <td>
                <div class="label">{{ $labels['quoteTo'] }}:</div>
                @if ($cu['name'] && $customerInfo['name'])<div>{{ $customerInfo['name'] }}</div>@endif
                @if ($cu['name'] && $customerInfo['company'])<div>{{ $customerInfo['company'] }}</div>@endif
                @if ($cu['email'] && $customerInfo['email'])<div>{{ $customerInfo['email'] }}</div>@endif
                @if ($cu['phone'] && $customerInfo['phone'])<div>{{ $customerInfo['phone'] }}</div>@endif
            </td>
            <td>
                @if ($cu['shipping'] && $shippingAddress)
                    <div class="label">{{ $labels['shipTo'] }}:</div>
                    <div>{{ $shippingAddress }}</div>
                @endif
            </td>
        </tr>
        <tr>
            <td>
                @if ($cu['billing'] && $billingAddress)
                    <div class="label">{{ $labels['billTo'] }}:</div>
                    <div>{{ $billingAddress }}</div>
                @endif
            </td>
            <td>
                @if ($q['salesperson'] && $salesperson['name'])
                    <div class="label">{{ $labels['salesperson'] }}:</div>
                    <div>{{ $salesperson['name'] }}</div>
                    @if ($salesperson['email'])<div>{{ $salesperson['email'] }}</div>@endif
                    @if ($salesperson['phone'])<div>{{ $salesperson['phone'] }}</div>@endif
                @endif
            </td>
            <td>
                @if ($cu['paymentTerms'] && $paymentTerms)
                    <div class="label">{{ $labels['paymentTerms'] }}:</div>
                    <div>{{ $paymentTerms }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ===== Products ===== --}}
    <table class="items">
        <thead>
            <tr>
                <th>{{ $labels['item'] }}</th>
                <th class="right" style="width: 50px;">{{ $labels['qty'] }}</th>
                <th class="right" style="width: 90px;">{{ $labels['price'] }}</th>
                <th class="right" style="width: 90px;">{{ $labels['total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                @php
                    $unit = (float) ($item->customer_requested_price ?? $item->original_price);
                    $orig = (float) $item->original_price;
                    $hasDiscount = $orig > 0 && $unit < $orig;
                    $ln = $lines[$item->id] ?? null;
                @endphp
                <tr>
                    <td>
                        <table class="inner">
                            <tr>
                                @if ($p['image'] && $item->image_url)
                                    <td style="width: 48px;">
                                        <img src="{{ $item->image_url }}" style="width: 40px;" alt="">
                                    </td>
                                @endif
                                <td>
                                    <strong>{{ $item->product_title }}</strong>
                                    @if ($p['variant'] && $item->variant_title && $item->variant_title !== 'Default Title')
                                        <div>{{ $item->variant_title }}</div>
                                    @endif
                                    @if ($p['sku'] && $item->sku)
                                        <div class="muted">SKU: {{ $item->sku }}</div>
                                    @endif
                                    @if ($p['discount'] && $hasDiscount)
                                        <div>Discount: {{ round((1 - $unit / $orig) * 100) }}% off</div>
                                         @if (($c['product']['discount'] ?? true) && $ln && $ln['has_discount'])
                                            <div>${{ number_format($ln['unit_after'], 2) }}</div>
                                            <div style="text-decoration:line-through; color:#c00;">${{ number_format($ln['unit_before'], 2) }}</div>
                                            <div>({{ $ln['label'] }})</div>
                                        @else
                                            <div>${{ number_format($ln['unit_before'] ?? ($item->customer_requested_price ?? $item->original_price), 2) }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">
                        {{ $money($unit) }}
                        @if ($p['discount'] && $hasDiscount)
                            <div class="strike">{{ $money($orig) }}</div>
                        @endif
                    </td>
                    <td class="right">{{ $money($item->line_total ?? $unit * $item->quantity) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ===== Note + totals ===== --}}
    <table style="margin-top: 18px;">
        <tr>
            <td style="width: 55%; padding-right: 20px;">
                @if ($note)
                    <div class="label">{{ $labels['note'] }}</div>
                    {!! nl2br(e($note)) !!}
                @endif
            </td>
            <td style="width: 45%;">
                <table class="totals">
                    @if ($q['subtotal'])
                        <tr><td>{{ $labels['subTotal'] }}</td><td class="right">{{ $money($totals['subtotal']) }}</td></tr>
                    @endif
                    {{-- "Display discount value on the PDF" tick hoy to j discount no amount dekhay --}}
                    @if ($q['discount'] && ($totals['discount'] == 0 || $quote->discount_show_on_pdf))
                        <tr><td>{{ $labels['discountRate'] }}</td><td class="right">{{ $totals['discount'] > 0 ? '-' : '' }}{{ $money($totals['discount']) }}</td></tr>
                    @endif
                    @if ($q['shipping'])
                        <tr><td>{{ $quote->shipping_name ?: $labels['shippingRate'] }}</td><td class="right">{{ $money($totals['shipping']) }}</td></tr>
                    @endif
                    @if ($q['tax'])
                        <tr><td>{{ $quote->tax_name ?: $labels['tax'] }}</td><td class="right">{{ $money($totals['tax']) }}</td></tr>
                    @endif
                    @if ($q['grandTotal'])
                        <tr class="grand"><td>{{ $labels['grandTotal'] }}</td><td class="right">{{ $money($totals['grand']) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

</body>
</html>