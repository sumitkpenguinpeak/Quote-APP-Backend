<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuoteStatusRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\Shop;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\ShopifyGraphQLException;
use App\Services\ShopifyDraftOrderService;
use App\Services\QuoteMailService;
use App\Jobs\SendQuoteNotificationEmail;
use App\Http\Requests\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\QuoteFieldValue;
use App\Models\QuoteItem;

class QuoteController extends Controller
{
    /**
     * GET /api/quotes?status=pending&search=...
     */
    public function index(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $quotes = Quote::query()
            ->where('shop_id', $shop->id)
            ->with(['customer', 'items', 'assignedStaff'])
            ->when($request->filled('status') && $request->status !== 'all', function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($sub) use ($term) {
                    $sub->where('quote_number', 'like', "%{$term}%")
                        ->orWhereHas('customer', function ($c) use ($term) {
                            $c->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('company_name', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return QuoteResource::collection($quotes);
    }

    /**
     * GET /api/quotes/{quote}
     */
    public function show(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        if (! $quote->viewed_at) {
            $quote->update(['viewed_at' => now()]);
            if ($quote->status === Quote::STATUS_PENDING) {
                $quote->update(['status' => Quote::STATUS_VIEWED]);
            }
        }

        $quote->load(['customer', 'items', 'messages.attachments', 'fieldValues.formField', 'assignedStaff']);

        return new QuoteResource($quote);
    }

    public function update(UpdateQuoteRequest $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        // Converted quotes remain editable when linked to a Shopify draft order,
        // so the merchant can change the quote and then sync those changes to Shopify.
        $blockedStatuses = [Quote::STATUS_APPROVED, Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED];
        if ($quote->status === Quote::STATUS_CONVERTED && ! $quote->shopify_draft_order_gid) {
            $blockedStatuses[] = Quote::STATUS_CONVERTED;
        }
        abort_if(
            in_array($quote->status, $blockedStatuses, true),
            422,
            'This quote can no longer be edited because it is already ' . $quote->status . '.'
        );

        $data = $request->validated();

        // Tax / Discount / Shipping settings are a Pro feature. Other plans can still
        // save the quote, as long as these values are not changed.
        $this->guardPricingSettings($quote, $data);

        DB::transaction(function () use ($quote, $data) {
            // 1. Customer info update (optional)
            if (isset($data['customer'])) {
                $quote->customer()->update(array_filter([
                    'first_name' => $data['customer']['first_name'] ?? null,
                    'last_name' => $data['customer']['last_name'] ?? null,
                    'email' => $data['customer']['email'] ?? null,
                    'phone' => $data['customer']['phone'] ?? null,
                    'company_name' => $data['customer']['company_name'] ?? null,
                    'address' => $data['customer']['address'] ?? null,
                    'city' => $data['customer']['city'] ?? null,
                    'state' => $data['customer']['state'] ?? null,
                    'country' => $data['customer']['country'] ?? null,
                    'zip' => $data['customer']['zip'] ?? null,
                ], fn ($v) => $v !== null));
            }

            // 2. Items sync (add new / update existing / remove missing)
            if (isset($data['items'])) {
                $incomingIds = collect($data['items'])->pluck('id')->filter()->all();

                // Existing items je payload ma nathi -> delete
                $quote->items()->whereNotIn('id', $incomingIds)->delete();

                foreach ($data['items'] as $itemData) {
                    $lineTotal = ($itemData['customer_requested_price'] ?? $itemData['original_price']) * $itemData['quantity'];

                    // Product wise discount (percentage = line total na %, fixed = poori line mathi ochhu)
                    $itemDiscountType = $itemData['discount_type'] ?? null;
                    $itemDiscountValue = (float) ($itemData['discount_value'] ?? 0);
                    if ($itemDiscountType && $itemDiscountValue > 0) {
                        $lineTotal -= $itemDiscountType === 'percentage'
                            ? $lineTotal * min($itemDiscountValue, 100) / 100
                            : $itemDiscountValue;
                    }
                    $lineTotal = max(round($lineTotal, 2), 0);

                    QuoteItem::updateOrCreate(
                        ['id' => $itemData['id'] ?? null, 'quote_id' => $quote->id],
                        [
                            'shopify_product_id' => $itemData['shopify_product_id'],
                            'shopify_variant_id' => $itemData['shopify_variant_id'] ?? null,
                            'product_title' => $itemData['product_title'],
                            'discount_type' => $itemDiscountValue > 0 ? $itemDiscountType : null,
                            'discount_value' => $itemDiscountValue > 0 ? $itemDiscountValue : null,
                            'status' => 'updated_at',
                            'variant_title' => $itemData['variant_title'] ?? null,
                            'sku' => $itemData['sku'] ?? null,
                            'image_url' => $itemData['image_url'] ?? null,
                            'quantity' => $itemData['quantity'],
                            'original_price' => $itemData['original_price'],
                            'customer_requested_price' => $itemData['customer_requested_price'] ?? null,
                            'line_total' => $lineTotal,
                            'properties' => array_values($itemData['properties'] ?? []),
                        ]
                    );
                }

                // subtotal / total niche recalculateTotals() ma set thase
            }

            // 3. Note / source update
            $quote->update(array_filter([
                'customer_note' => $data['note'] ?? null,
                'source' => $data['source'] ?? null,
            ], fn ($v) => $v !== null));

            // 3b. Discount / shipping / tax popups + merchant note + due date, pachhi totals recalculate
            $pricing = [];
            foreach (['discount_reason', 'shipping_name', 'tax_name', 'merchant_note', 'due_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $pricing[$field] = $data[$field];
                }
            }
            foreach (['discount_type', 'tax_type'] as $field) {
                if (array_key_exists($field, $data)) {
                    // frontend "amount" = DB ma 'fixed' (existing mail/migration convention)
                    $pricing[$field] = ($data[$field] ?? 'fixed') === 'percentage' ? 'percentage' : 'fixed';
                }
            }
            foreach (['discount_value', 'tax_value'] as $field) {
                if (array_key_exists($field, $data)) {
                    $pricing[$field] = $data[$field] ?? 0;
                }
            }
            if (array_key_exists('shipping', $data)) {
                $pricing['shipping_price'] = $data['shipping'] ?? 0;
            }
            foreach (['draft_order', 'email', 'invoice_pdf', 'pdf'] as $where) {
                if (array_key_exists("discount_show_on_{$where}", $data)) {
                    $pricing["discount_show_on_{$where}"] = (bool) $data["discount_show_on_{$where}"];
                }
            }

            $quote->fill($pricing)->recalculateTotals()->save();

            // 4. Custom field values update
            // if (isset($data['field_values'])) {
            //     foreach ($data['field_values'] as $fieldValue) {
            //         QuoteFieldValue::updateOrCreate(
            //             ['quote_id' => $quote->id, 'quote_form_field_id' => $fieldValue['field_id']],
            //             ['value' => $fieldValue['value'] ?? null]
            //         );
            //     }
            // }

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'updated',
                'performed_by_type' => 'staff',
                'description' => 'Quote details were updated',
            ]);
        });

        $this->notifyCustomer($request, $quote, QuoteMailService::TYPE_QUOTE_UPDATED);

        return new QuoteResource($quote->fresh(['customer', 'items', 'fieldValues']));
    }

    /**
     * Saved PDF template + quote data thi PDF (DomPDF) banave chhe. Export ane Send Proposal Email banne vapare chhe.
     */
    private function renderQuotePdf(Shop $shop, Quote $quote, ?string $lang = null)
    {
        $quote->load(['customer', 'items', 'assignedStaff']);

        $template = $this->resolveQuotePdfTemplate($shop);
        $labels = $this->resolvePdfLabels($template, $lang);
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

        // ---- product wise discount (PDF na price column mate) ----
        // keyed by item id: unit_before (cut), unit_after, discount_amount, label ("10% OFF"), line_total
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
                        : '$' . number_format($discountAmount, 2) . ' OFF')
                    : '',
                'line_total' => $lineTotal,
            ]];
        })->all();

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
            'name' => data_get($shop, 'shop', ''), // shops table ma `shop` = myshopify domain
            'phone' => data_get($shop, 'phone', ''),
            'address' => $this->formatAddress(data_get($shop, 'address')),
        ];
        $salesperson = [
            'name' => data_get($quote, 'assignedStaff.name', ''),
            'email' => data_get($quote, 'assignedStaff.email', ''),
            'phone' => data_get($quote, 'assignedStaff.phone', ''),
        ];

        return Pdf::setOption([
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
            'lines' => $lines,
            'dates' => $dates,
            'customerInfo' => $customerInfo,
            'store' => $store,
            'salesperson' => $salesperson,
            'shippingAddress' => $this->formatAddress(data_get($quote, 'shipping_address')),
            'billingAddress' => $this->formatAddress(data_get($quote, 'billing_address')),
            'paymentTerms' => data_get($quote, 'payment_terms', ''),
            'note' => data_get($quote, 'merchant_note', ''),
        ])->setPaper('a4');
    }

    /**
     * GET /api/quotes/{quote}/export-pdf
     *   ?lang=English   -> template ma "Auto detect language" ON hoy tyare language pick kare
     *   ?inline=1       -> download ni jagyae browser ma kholo
     *
     * Merchant e PDF Template editor ma je quote_pdf_template save kari hoy te j vaapre chhe
     * (style, content toggles, translation labels) ane Blade view `pdf.quote` render kare chhe.
     */
    public function exportPdf(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        $pdf = $this->renderQuotePdf($shop, $quote, $request->query('lang'));

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'exported',
            'performed_by_type' => 'staff',
            'description' => 'Quote was exported as PDF',
        ]);

        $filename = 'quote-' . ($quote->quote_number ?? $quote->id) . '.pdf';

        return $request->boolean('inline')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    /**
     * POST /api/quotes/{quote}/draft-order
     *
     * Quote mathi Shopify Draft Order banave chhe (items, quoted price, discount, shipping, tax sathe),
     * ane draft order id + invoice url quote par save kari quote ne "converted" kari de chhe.
     * Shopify scope: write_draft_orders
     */
    public function syncToDraftOrder(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);
        $this->requirePlanFeature($quote->shop, 'sync', 'Sync data');
        try {
            $updated = ShopifyDraftOrderService::for($quote)->updateExistingDraftOrder();
            return response()->json(['success' => true, 'message' => 'Draft order updated from quote.', 'data' => new QuoteResource($updated)]);
        } catch (\Throwable $e) {
            Log::error('Quote to draft order sync failed', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage() ?: 'Could not update Shopify draft order.'], 422);
        }
    }

    public function syncFromDraftOrder(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);
        $this->requirePlanFeature($quote->shop, 'sync', 'Sync data');
        try {
            $draft = ShopifyDraftOrderService::for($quote)->fetchExistingDraftOrder();
            if (empty($draft)) {
                return response()->json(['message' => 'Shopify draft order was not found.'], 404);
            }
            DB::transaction(function () use ($quote, $draft) {
                $quote->load('items');
                foreach (($draft['lineItems']['edges'] ?? []) as $edge) {
                    $line = $edge['node'] ?? [];
                    $variantGid = $line['variant']['id'] ?? null;
                    $variantId = $variantGid ? basename(str_replace('?', '', $variantGid)) : null;
                    $item = $variantId ? $quote->items->firstWhere('shopify_variant_id', $variantId) : null;
                    if (! $item) {
                        $item = $quote->items->first(fn ($i) => ! $i->shopify_variant_id && $i->product_title === ($line['title'] ?? ''));
                    }
                    if (! $item) continue;
                    $qty = max(1, (int) ($line['quantity'] ?? $item->quantity));
                    $price = (float) ($line['originalUnitPrice']['amount'] ?? $item->original_price);
                    $item->update([
                        'quantity' => $qty,
                        'original_price' => $price,
                        'customer_requested_price' => $price,
                        'line_total' => $price * $qty,
                    ]);
                }
                $quote->recalculateTotals()->save();
                QuoteActivityLog::create(['quote_id' => $quote->id, 'action' => 'updated', 'performed_by_type' => 'staff', 'description' => 'Quote synced from Shopify draft order', 'meta' => ['draft_order_gid' => $quote->shopify_draft_order_gid]]);
            });
            return response()->json(['success' => true, 'message' => 'Quote synced from Shopify draft order.', 'data' => new QuoteResource($quote->fresh(['customer', 'items', 'fieldValues']))]);
        } catch (\Throwable $e) {
            Log::error('Draft order to quote sync failed', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage() ?: 'Could not sync from Shopify draft order.'], 422);
        }
    }

    public function createDraftOrder(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);
        $this->requirePlanFeature($quote->shop, 'draft_order', 'Convert Quote to Draft Order');

        // install vakhate malela scopes ma write_draft_orders hovu joiye
        abort_if(
            ! \App\Models\Shop::scopeGranted((string) data_get($quote->shop, 'scope', ''), 'write_draft_orders'),
            403,
            'This store does not have the write_draft_orders scope. Add the scope in the app, then uninstall and reinstall the app on the store.'
        );

        // double-click / be tab thi be draft order na bane te mate lock
        $lock = Cache::lock("draft-order:quote:{$quote->id}", 60);
        abort_unless($lock->get(), 409, 'A draft order is already being created. Please wait a moment.');

        try {
            $quote->refresh()->load('items');

            abort_if(
                filled($quote->shopify_draft_order_gid),
                422,
                'A draft order has already been created for this quote.'
            );
            abort_if(
                in_array($quote->status, [Quote::STATUS_REJECTED, Quote::STATUS_CANCELLED, Quote::STATUS_EXPIRED], true),
                422,
                "This quote is {$quote->status}, so a draft order cannot be created."
            );
            abort_if($quote->items->isEmpty(), 422, 'This quote has no products.');

            $quote = ShopifyDraftOrderService::for($quote)->createFromQuote();
        } catch (ShopifyGraphQLException $e) {
            Log::error('Draft order creation failed', [
                'quote_id' => $quote->id,
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);

            abort(422, 'Could not create the Shopify draft order: ' . $e->getMessage());
        } finally {
            $lock->release();
        }

        return new QuoteResource($quote->loadMissing(['customer', 'items']));
    }

    /**
     * GET /api/quotes/{quote}/shipping-rates
     *
     * Store ni Shopify shipping settings pramane eligible rates ("Add shipping" popup > "Shopify shipping rate").
     * Hamesha 200 aape chhe; rates na male to `reason` ma karan hoy chhe, jethi popup ma banner batavi shakay:
     *   no_address | no_items | no_rates | shopify_error
     */
    public function shippingRates(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        try {
            $result = ShopifyDraftOrderService::for($quote)->availableShippingRates();
        } catch (ShopifyGraphQLException $e) {
            Log::warning('Shipping rates lookup failed', [
                'quote_id' => $quote->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => ['rates' => [], 'reason' => 'shopify_error', 'message' => $e->getMessage()],
            ]);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * PATCH /api/quotes/{quote}/status
     * Writes the new status to `quotes`, stamps the relevant timestamp
     * column, and appends a row to quote_activity_logs.
     */
    public function updateStatus(UpdateQuoteStatusRequest $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        $data = $request->validated();
        $previousStatus = $quote->status;

        abort_if(
            $data['status'] === Quote::STATUS_CANCELLED
                && in_array($previousStatus, [Quote::STATUS_CONVERTED, Quote::STATUS_CANCELLED], true),
            422,
            "This quote is already {$previousStatus}, so it cannot be cancelled."
        );

        DB::transaction(function () use ($quote, $data, $previousStatus) {
            $timestampColumn = match ($data['status']) {
                'pending' => 'pending',
                'read' => 'read',
                'update' => 'updated_at',
                'approved' => 'approved_at',
                'rejected' => 'rejected_at',
                'converted' => 'converted_at',
                default => null,
            };


            $updates = ['status' => $data['status']];
            if (! empty($data['merchant_note'])) {
                $updates['merchant_note'] = $data['merchant_note'];
            }
            if ($timestampColumn) {
                $updates[$timestampColumn] = now();
            }

            $quote->update($updates);

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'status_changed',
                'performed_by_type' => 'staff',
                'description' => "Status changed from {$previousStatus} to {$data['status']}",
                'meta' => ['from' => $previousStatus, 'to' => $data['status']],
            ]);
        });

        if ($data['status'] === Quote::STATUS_CANCELLED && $previousStatus !== Quote::STATUS_CANCELLED) {
            $this->notifyCustomer($request, $quote, QuoteMailService::TYPE_QUOTE_CANCELLED);
        }

        return new QuoteResource($quote->fresh(['customer', 'items']));
    }

    /**
     * Customer ne notification email (queue ma) moke chhe. Mail ma problem hoy to quote save/cancel fail nathi thatu.
     */
    private function notifyCustomer(Request $request, Quote $quote, string $type): void
    {
        try {
            $shop = $request->attributes->get('shop');
            $email = $quote->customer()->value('email');
            if (! $shop || ! $email) {
                return;
            }

            SendQuoteNotificationEmail::dispatch($shop->id, $type, $email, [], $quote->id);
        } catch (\Throwable $e) {
            Log::warning("Customer email dispatch failed ({$type}) for quote {$quote->id}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/quotes/{quote}/send-proposal
     * Body: { message?: string }
     * Customer ne quote PDF sathe proposal email moke chhe (merchant ni email template "quote_proposal" mujab).
     */
    public function sendProposal(Request $request, Quote $quote, QuoteMailService $mailService)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        $data = $request->validate(['message' => ['nullable', 'string', 'max:2000']]);

        /** @var Shop $shop */
        $shop = $request->attributes->get('shop');

        abort_if(
            in_array($quote->status, [Quote::STATUS_CANCELLED, Quote::STATUS_EXPIRED], true),
            422,
            "This quote is {$quote->status}, so the proposal email cannot be sent."
        );

        $quote->load(['customer', 'items', 'assignedStaff']);
        $email = $quote->customer->email ?? null;
        abort_if(! $email, 422, "This quote's customer has no email address.");

        // PDF taaji (saved data thi) banavo - jooni cached PDF nahi
        $pdf = $this->renderQuotePdf($shop, $quote, null);
        $pdfAttachment = [$pdf->output(), 'quote-' . ($quote->quote_number ?? $quote->id) . '.pdf'];

        $message = trim((string) ($data['message'] ?? ''));
        $log = $mailService->send(
            $shop,
            QuoteMailService::TYPE_QUOTE_PROPOSAL,
            $email,
            ['custom_message' => $message !== '' ? nl2br(e($message)) : ''],
            $quote,
            $pdfAttachment
        );

        abort_if($log->status !== 'sent', 502, 'Failed to send email: ' . ($log->error_message ?: 'unknown error'));

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'proposal_sent',
            'performed_by_type' => 'staff',
            'description' => "Proposal email sent to {$email}",
            'meta' => ['to' => $email],
        ]);

        return response()->json(['message' => "Proposal email sent to {$email}.", 'to' => $email]);
    }

    /**
     * POST /api/quotes/{quote}/duplicate
     * Quote ni copy banave chhe (navo quote number, status pending, items / pricing / notes / field values sathe).
     * Draft order, PDF ane status timestamps copy nathi thata.
     */
    public function duplicate(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        $quote->loadMissing(['items', 'fieldValues']);

        $copy = DB::transaction(function () use ($quote) {
            $copy = Quote::create([
                'shop_id' => $quote->shop_id,
                'customer_id' => $quote->customer_id,
                'assigned_staff_id' => $quote->assigned_staff_id,
                'quote_number' => Quote::nextQuoteNumber($quote->shop),
                'status' => Quote::STATUS_PENDING,
                'source' => $quote->source,
                'currency' => $quote->currency,
                'discount_type' => $quote->discount_type,
                'discount_value' => $quote->discount_value,
                'discount_reason' => $quote->discount_reason,
                'discount_show_on_draft_order' => $quote->discount_show_on_draft_order,
                'discount_show_on_email' => $quote->discount_show_on_email,
                'discount_show_on_invoice_pdf' => $quote->discount_show_on_invoice_pdf,
                'discount_show_on_pdf' => $quote->discount_show_on_pdf,
                'shipping_price' => $quote->shipping_price,
                'shipping_name' => $quote->shipping_name,
                'tax_type' => $quote->tax_type,
                'tax_value' => $quote->tax_value,
                'tax_name' => $quote->tax_name,
                'customer_note' => $quote->customer_note,
                'merchant_note' => $quote->merchant_note,
                'expires_at' => $quote->expires_at && $quote->expires_at->isFuture()
                    ? $quote->expires_at
                    : now()->addDays($quote->shop->settings->quote_expiry_days ?? 7),
            ]);

            foreach ($quote->items as $item) {
                $copy->items()->create($item->only([
                    'shopify_product_id', 'shopify_variant_id', 'product_title', 'variant_title', 'sku', 'image_url',
                    'quantity', 'original_price', 'customer_requested_price', 'merchant_offered_price', 'final_price',
                    'discount_type', 'discount_value', 'line_total', 'note', 'properties',
                ]));
            }

            foreach ($quote->fieldValues as $fv) {
                $copy->fieldValues()->create($fv->only(['quote_form_field_id', 'label', 'value']));
            }

            $copy->recalculateTotals()->save();

            QuoteActivityLog::create([
                'quote_id' => $copy->id,
                'action' => 'created',
                'performed_by_type' => 'staff',
                'description' => "Quote was duplicated from {$quote->quote_number}",
                'meta' => ['duplicated_from' => $quote->id],
            ]);

            return $copy;
        });

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'duplicated',
            'performed_by_type' => 'staff',
            'description' => "Quote was duplicated as {$copy->quote_number}",
            'meta' => ['duplicated_to' => $copy->id],
        ]);

        return (new QuoteResource($copy->fresh(['customer', 'items'])))->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/quotes/{quote}
     * Quote ane tena related data (items, field values, messages, attachments, activity logs)
     * kaayam mate delete kare chhe. Shopify ma banelo draft order delete nathi thato.
     */
    public function destroy(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        $quote->loadMissing('attachments');
        $files = $quote->attachments->pluck('file_path')->filter()->all();
        if ($quote->pdf_path) {
            $files[] = $quote->pdf_path;
        }
        $number = $quote->quote_number;

        DB::transaction(function () use ($quote) {
            $quote->items()->delete();
            $quote->fieldValues()->delete();
            $quote->messages()->delete();
            $quote->attachments()->delete();
            $quote->activityLogs()->delete();
            $quote->staffAssignments()->detach();
            $quote->delete();
        });

        // DB delete thaya pachhi files saaf karo (fail thay to quote delete rakhvo)
        foreach ($files as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                Log::warning("Quote file delete failed: {$path}", ['error' => $e->getMessage()]);
            }
        }

        Log::info("Quote {$number} deleted", ['shop_id' => $quote->shop_id]);

        return response()->json(['message' => 'Quote deleted.']);
    }

    /**
     * PATCH /api/quotes/{quote}/assign
     */
    public function assignStaff(Request $request, Quote $quote)
    {
        $this->authorizeShopOwnsQuote($request, $quote);

        $request->validate(['staff_member_id' => ['required', 'exists:staff_members,id']]);

        $quote->update(['assigned_staff_id' => $request->staff_member_id]);

        QuoteActivityLog::create([
            'quote_id' => $quote->id,
            'action' => 'assigned',
            'performed_by_type' => 'staff',
            'description' => 'Quote assigned to staff member',
            'meta' => ['staff_member_id' => $request->staff_member_id],
        ]);

        return new QuoteResource($quote->fresh(['assignedStaff']));
    }

    private function authorizeShopOwnsQuote(Request $request, Quote $quote): void
    {
        $shop = $request->attributes->get('shop');
        abort_unless($quote->shop_id === $shop->id, 403, 'This quote does not belong to your store.');
    }

    /** 403 unless the shop's current plan unlocks $feature. */
    private function requirePlanFeature(Shop $shop, string $feature, string $label): void
    {
        abort_unless(
            $shop->canUse($feature),
            403,
            "{$label} is not available on your current plan. Please upgrade your plan to use it."
        );
    }

    /**
     * Reject changes to the quote's tax / discount / shipping values when the plan
     * does not include "pricing_settings". Unchanged values (the editor sends the
     * whole quote on every save) are allowed.
     */
    private function guardPricingSettings(Quote $quote, array $data): void
    {
        if ($quote->shop->canUse('pricing_settings')) {
            return;
        }

        $numeric = ['discount_value' => 'discount_value', 'tax_value' => 'tax_value', 'shipping' => 'shipping_price'];
        $text = ['discount_reason' => 'discount_reason', 'shipping_name' => 'shipping_name', 'tax_name' => 'tax_name'];

        $changed = false;
        foreach ($numeric as $input => $column) {
            if (array_key_exists($input, $data) && round((float) $data[$input], 2) !== round((float) $quote->{$column}, 2)) {
                $changed = true;
            }
        }
        foreach ($text as $input => $column) {
            if (array_key_exists($input, $data) && trim((string) $data[$input]) !== trim((string) $quote->{$column})) {
                $changed = true;
            }
        }

        abort_if(
            $changed,
            403,
            'Tax, Discount and Shipping settings are available on the Pro plan. Please upgrade your plan to use them.'
        );
    }

    /* =====================================================================
     |  PDF export helpers
     * ===================================================================== */

    /**
     * Saved quote_pdf_template (settings > extra_settings) ne defaults sathe merge kare chhe.
     */
    private function resolveQuotePdfTemplate(Shop $shop): array
    {
        // TODO: tamara settings model/relation pramane badlo (jya `extra_settings` save thay chhe)
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

    // Source: DefaultTemplates::quotePdf()
    private function defaultQuotePdfTemplate(): array
    {
        return \App\Support\DefaultTemplates::quotePdf();
    }

    /**
     * Auto detect ON hoy ane ?lang= match thay to e language, nahi to default language na labels.
     */
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

    // DomPDF na built-in fonts ma map kare chhe
    private function pdfFont(string $name): string
    {
        return match ($name) {
            'Arial' => 'Helvetica',
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