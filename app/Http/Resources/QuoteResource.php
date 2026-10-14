<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_number' => $this->quote_number,
            'status' => $this->status,
            'source' => $this->source,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            // DB ma 'fixed' / 'percentage'; frontend 'amount' / 'percentage' vanche chhe
            'discount_type' => $this->discount_type === 'fixed' ? 'amount' : $this->discount_type,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'discount' => (float) $this->discount_amount,
            'discount_amount' => (float) $this->discount_amount,
            'discount_reason' => $this->discount_reason,
            'discount_show_on_draft_order' => (bool) $this->discount_show_on_draft_order,
            'discount_show_on_email' => (bool) $this->discount_show_on_email,
            'discount_show_on_invoice_pdf' => (bool) $this->discount_show_on_invoice_pdf,
            'discount_show_on_pdf' => (bool) $this->discount_show_on_pdf,
            'shipping' => (float) $this->shipping_price,
            'shipping_price' => (float) $this->shipping_price,
            'shipping_name' => $this->shipping_name,
            'tax' => (float) $this->tax_amount,
            'tax_type' => $this->tax_type === 'fixed' ? 'amount' : $this->tax_type,
            'tax_value' => (float) $this->tax_value,
            'tax_amount' => (float) $this->tax_amount,
            'tax_name' => $this->tax_name,
            'due_date' => $this->due_date?->toDateString(),
            'total' => (float) $this->total,
            'customer_note' => $this->customer_note,
            'merchant_note' => $this->merchant_note,
            'shopify_draft_order_id' => $this->shopify_draft_order_id,
            'checkout_url' => $this->checkout_url,
            'invoice_number' => $this->invoice_number,
            'invoice_issued_at' => $this->invoice_issued_at,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            // Quote form na custom fields (customer e bharela extra details)
            'field_values' => $this->whenLoaded('fieldValues', fn () => $this->fieldValues->map(fn ($fv) => [
                'label' => $fv->label ?: ($fv->formField->label ?? ''),
                'value' => $fv->value,
            ])->values()),
            'messages' => QuoteMessageResource::collection($this->whenLoaded('messages')),
            'assigned_staff' => $this->whenLoaded('assignedStaff', fn () => [
                'id' => $this->assignedStaff->id,
                'name' => $this->assignedStaff->name,
            ]),
        ];
    }
}