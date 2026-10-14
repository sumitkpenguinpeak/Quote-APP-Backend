<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateQuoteItemOfferRequest;
use App\Http\Resources\QuoteItemResource;
use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\QuoteItem;
use Illuminate\Support\Facades\DB;

class QuoteItemController extends Controller
{
    /**
     * PATCH /api/quotes/{quote}/items/{item}/offer
     * Merchant proposes a price for one line item. Recalculates the
     * item's line_total and the parent quote's total.
     */
    public function updateOffer(UpdateQuoteItemOfferRequest $request, Quote $quote, QuoteItem $item)
    {
        abort_unless($quote->shop_id === $request->attributes->get('shop')->id, 403);
        abort_unless($item->quote_id === $quote->id, 404);

        $data = $request->validated();

        DB::transaction(function () use ($quote, $item, $data) {
            $lineTotal = $data['merchant_offered_price'] * $item->quantity;

            if (($data['discount_type'] ?? null) === 'percentage') {
                $lineTotal -= $lineTotal * (($data['discount_value'] ?? 0) / 100);
            } elseif (($data['discount_type'] ?? null) === 'fixed') {
                $lineTotal -= $data['discount_value'] ?? 0;
            }

            $item->update([
                'merchant_offered_price' => $data['merchant_offered_price'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? null,
                'line_total' => max($lineTotal, 0),
            ]);

            $newTotal = $quote->items()->sum('line_total');
            $quote->update([
                'total' => $newTotal,
                'status' => $quote->status === Quote::STATUS_PENDING ? Quote::STATUS_NEGOTIATING : $quote->status,
            ]);

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'price_offered',
                'performed_by_type' => 'staff',
                'description' => "Offered price {$data['merchant_offered_price']} for {$item->product_title}",
            ]);
        });

        return new QuoteItemResource($item->fresh());
    }
}
