<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteMessageRequest;
use App\Http\Resources\QuoteMessageResource;
use App\Models\Quote;
use App\Models\QuoteActivityLog;
use App\Models\QuoteAttachment;
use App\Models\QuoteMessage;
use Illuminate\Support\Facades\DB;

class QuoteMessageController extends Controller
{
    /**
     * POST /api/quotes/{quote}/messages
     * Adds a message to the negotiation thread. If sent by the merchant
     * on a still-pending quote, the quote automatically moves to "negotiating".
     */
    public function store(StoreQuoteMessageRequest $request, Quote $quote)
    {
        abort_unless($quote->shop_id === $request->attributes->get('shop')->id, 403);

        $data = $request->validated();

        $message = DB::transaction(function () use ($quote, $data) {
            $message = QuoteMessage::create([
                'quote_id' => $quote->id,
                'sender_type' => $data['sender_type'],
                'sender_staff_id' => $data['sender_type'] === 'merchant' ? auth()->id() : null,
                'message' => $data['message'],
            ]);

            foreach ($data['attachments'] ?? [] as $attachment) {
                QuoteAttachment::create([
                    'quote_id' => $quote->id,
                    'quote_message_id' => $message->id,
                    'file_name' => $attachment['file_name'],
                    'file_path' => $attachment['file_path'],
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'file_size' => $attachment['file_size'] ?? null,
                ]);
            }

            if ($data['sender_type'] === 'merchant' && $quote->status === Quote::STATUS_PENDING) {
                $quote->update(['status' => Quote::STATUS_NEGOTIATING]);
            }

            QuoteActivityLog::create([
                'quote_id' => $quote->id,
                'action' => 'message_sent',
                'performed_by_type' => $data['sender_type'] === 'merchant' ? 'staff' : 'customer',
                'description' => 'New message added to negotiation thread',
            ]);

            return $message;
        });

        // TODO: dispatch NewQuoteMessage notification job here

        return (new QuoteMessageResource($message->load('attachments')))
            ->response()
            ->setStatusCode(201);
    }
}
