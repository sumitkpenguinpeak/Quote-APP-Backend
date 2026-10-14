<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Jobs\SendQuoteNotificationEmail;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\QuoteFieldValue;
use App\Models\QuoteFormField;
use App\Models\QuoteItem;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ShopifyStorefrontService;
use App\Services\StorefrontShippingRateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Services\QuoteMailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Bus;
use App\Jobs\GenerateQuotePdf;

class QuoteRequestController extends Controller
{
    /**
     * POST /api/storefront/quotes
     * Called from the theme's "Request a Quote" form. Creates the quote,
     * its line items, the customer record (or reuses one), and any custom
     * form field answers - all inside a single DB transaction.
     */
    public function store(StoreQuoteRequest $request)
    {
        /** @var Shop $shop */
        $shop = Shop::where('shop', $request->shop)->first();
        $data = $request->validated();

        // Plan limit (Free 10 / Starter 50 / Pro unlimited quotes per month)
        if ($shop->hasReachedQuoteLimit()) {
            return response()->json([
                'success' => false,
                'code' => 'quote_limit_reached',
                'message' => 'This store has reached its monthly quote limit. Please contact the store owner.',
            ], 403);
        }

        // Customer e shipping rate select karyo hoy to price client par bharoso na karta, server par pachho calculate
        $shipping = null;
        if (! empty($data['shipping']['handle'])) {
            try {
                $c = $data['customer'];
                $result = StorefrontShippingRateService::for($shop)->rates($data['items'], [
                    'country' => $c['country'] ?? null,
                    'country_code' => $c['country_code'] ?? null,
                    'state' => $c['state'] ?? null,
                    'state_code' => $c['state_code'] ?? null,
                    'city' => $c['city'] ?? null,
                    'zip' => $c['zip'] ?? null,
                    'address' => $c['address'] ?? null,
                ]);
                $shipping = collect($result['rates'])->firstWhere('handle', $data['shipping']['handle']);
            } catch (\Throwable $e) {
                Log::warning('Storefront shipping rate verify failed', ['shop' => $shop->shop, 'message' => $e->getMessage()]);
            }
        }

        $quote = DB::transaction(function () use ($data, $shop, $shipping) {
            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'email' => $data['customer']['email'],
                ],
                [
                    'shopify_customer_id' => $data['customer']['shopify_customer_id'] ?? null,
                    'first_name' => $data['customer']['first_name'],
                    'last_name' => $data['customer']['last_name'] ?? null,
                    'phone' => $data['customer']['phone'] ?? null,
                    'company_name' => $data['customer']['company_name'] ?? null,
                    'address' => $data['customer']['address'] ?? null,
                    'city' => $data['customer']['city'] ?? null,
                    'state' => $data['customer']['state'] ?? null,
                    'country' => $data['customer']['country'] ?? null,
                    'zip' => $data['customer']['zip'] ?? null,
                    'is_b2b' => ! empty($data['customer']['company_name']),
                ]
            );

            $settings = $shop->settings;
            $expiryDays = $settings->quote_expiry_days ?? 7;

            $quote = Quote::create([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'quote_number' => $this->generateQuoteNumber($shop),
                'status' => Quote::STATUS_PENDING,
                'source' => $data['source'] ?? 'product_page',
                'currency' => $shop->currency ? $shop->currency : "USD",
                'customer_note' => $data['note'] ?? null,
                'expires_at' => now()->addDays($expiryDays),
            ]);

            $subtotal = 0;

            foreach ($data['items'] as $itemData) {
                $lineTotal = ($itemData['customer_requested_price'] ?? $itemData['original_price']) * $itemData['quantity'];
                $subtotal += $itemData['original_price'] * $itemData['quantity'];

                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'shopify_product_id' => $itemData['shopify_product_id'],
                    'shopify_variant_id' => $itemData['shopify_variant_id'] ?? null,
                    'product_title' => $itemData['product_title'],
                    'variant_title' => $itemData['variant_title'] ?? null,
                    'sku' => $itemData['sku'] ?? null,
                    'image_url' => $itemData['image_url'] ?? null,
                    'quantity' => $itemData['quantity'],
                    'original_price' => $itemData['original_price'],
                    'customer_requested_price' => $itemData['customer_requested_price'] ?? null,
                    'line_total' => $lineTotal,
                ]);
            }

            $shippingPrice = $shipping ? round((float) $shipping['price'], 2) : 0;

            $quote->update([
                'subtotal' => $subtotal,
                'shipping_price' => $shippingPrice,
                'shipping_name' => $shipping['title'] ?? null,
                'total' => $subtotal + $shippingPrice,
            ]);

            foreach ($data['field_values'] ?? [] as $fieldValue) {
                $field = QuoteFormField::where('shop_id', $shop->id)->find($fieldValue['field_id']);

                QuoteFieldValue::create([
                    'quote_id' => $quote->id,
                    'quote_form_field_id' => $field?->id,
                    'label' => $field?->label ?? 'Field',
                    'value' => $fieldValue['value'] ?? null,
                ]);
            }

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'created',
                'performed_by_type' => 'customer',
                'description' => "Quote request submitted by {$customer->first_name} {$customer->last_name}",
            ]);

            return $quote;
        });

        //   GenerateQuotePdf::dispatch($quote->id);


        // Merchant notification goes to the merchant's own configured address (never a hardcoded one).
        $merchantEmail = $shop->settings?->notification_email;

        $jobs = [new GenerateQuotePdf($quote->id)];
        if ($merchantEmail) {
            $jobs[] = new SendQuoteNotificationEmail($shop->id, QuoteMailService::TYPE_NEW_QUOTE_TO_MERCHANT, $merchantEmail, [], $quote->id);
        }
        $jobs[] = new SendQuoteNotificationEmail($shop->id, QuoteMailService::TYPE_QUOTE_RECEIVED_TO_CUSTOMER, $quote->customer->email, [], $quote->id);

        Bus::chain($jobs)->dispatch();

        // if ($shop->settings?->notify_merchant_on_new_quote && $shop->settings->notification_email) {
            // SendQuoteNotificationEmail::dispatch(
            //     $shop->id,
            //     QuoteMailService::TYPE_NEW_QUOTE_TO_MERCHANT,
            //     'sumitkpenguinpeak@gmail.com',
            //     [],
            //     $quote->id,
            // );
        // }

        // SendQuoteNotificationEmail::dispatch(
        //     $shop->id,
        //     QuoteMailService::TYPE_QUOTE_RECEIVED_TO_CUSTOMER,
        //     $quote->customer->email,
        //     [],
        //     $quote->id,
        // );

        // TODO: dispatch NewQuoteReceived notification/email job here
        
        return (new QuoteResource($quote->load(['items', 'customer', 'fieldValues'])))
            ->additional([
                'success' => true,
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function exportPdf(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $quote->load(['customer', 'items', 'assignedStaff']);

        $template = $this->resolveQuotePdfTemplate($shop);
        $labels = $this->resolvePdfLabels($template, $request->query('lang'));
        $style = $template['style'];
        $content = $template['content'];

        // ---- totals (column names tamara Quote table pramane adjust karo) ----
        // Items + discount/shipping/tax thi taaji calculate (Quote::recalculateTotals) - save kareli value sathe j match thay
        $quote->recalculateTotals();
        $totals = [
            'subtotal' => (float) $quote->subtotal,
            'discount' => (float) $quote->discount_amount,
            'shipping' => (float) $quote->shipping_price,
            'tax' => (float) $quote->tax_amount,
            'grand' => (float) $quote->total,
        ];

        // ---- date format (editor ma select kareli format) ----
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
            'isRemoteEnabled' => true,       // product image URL (Shopify CDN) load karva mate
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
        ])->setPaper('a4');

        // QuoteActivityLog::create([
        //     'quote_id' => $quote->id,
        //     'action' => 'exported',
        //     'performed_by_type' => 'staff',
        //     'description' => 'Quote was exported as PDF',
        // ]);

        $pdfContent = $pdf->output();
        $filename = 'quote-' . Str::slug((string) ($quote->quote_number ?? $quote->id)) . '.pdf';
        $path = 'quotes/quote-' . $filename;

        Storage::disk('public')->put($path, $pdfContent);

        $quote->update(['pdf_path' => $path]);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

    }
    
    private function generateQuoteNumber(Shop $shop): string
    {
        return Quote::nextQuoteNumber($shop);
    }

    // 
    public function createCart(
        Request $request,
        ShopifyStorefrontService $shopify
    ) {
        $shop = Shop::where('shop', $request->shop)->first();

        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop not found',
            ], 404);
        }

        if (empty($shop->storefront_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Storefront token not found',
            ], 400);
        }

        $store = [
            'id' => $shop->id,
            'shop' => $shop->shop,
            'storefront_token' => $shop->storefront_token,
        ];

        $query = <<<'GRAPHQL'
        mutation cartCreate($input: CartInput) {
            cartCreate(input: $input) {
                cart {
                    id
                    checkoutUrl
                }

                userErrors {
                    field
                    message
                }

                warnings {
                    code
                    message
                }
            }
        }
        GRAPHQL;

        $variables = [
            'input' => [
                'lines' => [
                    [
                        'quantity' => 1,
                        'merchandiseId' =>
                            'gid://shopify/ProductVariant/54207807947062'
                    ]
                ]
            ]
        ];

        $result = $shopify->query(
            $query,
            $variables,
            $store
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
    
}