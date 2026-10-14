<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Support\DefaultTemplates;
use Illuminate\Http\Request;
use App\Services\QuoteMailService;

class EmailTemplateController extends Controller
{
    /** GET /api/email-templates */
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $saved = EmailTemplate::where('shop_id', $shop->id)->get()->keyBy('type');

        $types = [
            QuoteMailService::TYPE_NEW_QUOTE_TO_MERCHANT,
            QuoteMailService::TYPE_QUOTE_RECEIVED_TO_CUSTOMER,
            QuoteMailService::TYPE_QUOTE_APPROVED,  
            QuoteMailService::TYPE_QUOTE_REJECTED,
            QuoteMailService::TYPE_NEW_MESSAGE,
            QuoteMailService::TYPE_QUOTE_CONVERTED,
            QuoteMailService::TYPE_QUOTE_EXPIRING_SOON,
            QuoteMailService::TYPE_QUOTE_PROPOSAL,
            QuoteMailService::TYPE_QUOTE_UPDATED,
            QuoteMailService::TYPE_QUOTE_CANCELLED,
            QuoteMailService::TYPE_INVOICE_SENT,
        ];

        // Install vakhate default rows DB ma save thay chhe, etle "customized" = default thi alag hoy tyare j.
        $data = collect($types)->map(fn ($type) => [
            'type' => $type,
            'is_customized' => $saved->has($type)
                && [trim($saved[$type]->subject), trim($saved[$type]->body_html)] !== array_map('trim', DefaultTemplates::email($type)),
            'subject' => $saved[$type]->subject ?? null,
            'body_html' => $saved[$type]->body_html ?? null,
            'is_full_document' => $saved[$type]->is_full_document ?? false,
            'is_active' => $saved[$type]->is_active ?? true,
        ]);

        return response()->json(['data' => $data]);

    }   

    public function show(Request $request, string $type)
    {
        $shop = $request->attributes->get('shop');
        $template = EmailTemplate::where('shop_id', $shop->id)->where('type', $type)->first();
        return response()->json(['data' => $template]);
    }   

    /**
     * PUT /api/email-templates/{type}
     * Body: { subject: "...", body_html: "...", is_active: true }
     * Creates the row if the merchant hasn't customized this type yet.
     */
    public function update(Request $request, string $type)
    {
        $shop = $request->attributes->get('shop');

        abort_unless(
            $shop->canUse('templates'),
            403,
            'Modifying mail templates is available on the Pro plan. Please upgrade your plan to use it.'
        );

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'is_full_document' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $template = EmailTemplate::updateOrCreate(
            ['shop_id' => $shop->id, 'type' => $type],
            $data
        );

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, string $type)
    {
        $shop = $request->attributes->get('shop');

        // Row delete karvani jagyae default subject/body pachha DB ma lakhi do (default hamesha DB ma rahe).
        [$subject, $body] = DefaultTemplates::email($type);

        EmailTemplate::updateOrCreate(
            ['shop_id' => $shop->id, 'type' => $type],
            ['subject' => $subject, 'body_html' => $body, 'is_active' => true]
        );

        return response()->json(['message' => 'Reverted to default template.']);
    }

    /** POST /api/email-templates/{type}/test */
    public function sendTest(Request $request, string $type)
    {
        $shop = $request->attributes->get('shop');
        $request->validate(['email' => ['required', 'email']]);


        // echo "type". $type;
        // die;

        $log = app(\App\Services\QuoteMailService::class)->send(
            $shop,
            $type,
            $request->email,
            [
                'customer_name' => 'Test Customer',
                'quote_number' => 'Q-TEST-001',
                'quote_total' => $shop->currency . '999.00',
                'quote_items_count' => '2',
            ],
        );

        // send() never throws (it records the result in notifications_log), so
        // report a failure to the frontend instead of always saying "queued".
        abort_if(
            $log->status === 'failed',
            502,
            'Test email failed: ' . ($log->error_message ?: 'unknown error')
        );

        return response()->json(['message' => 'Test email sent.']);
    }
}