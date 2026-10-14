<?php

namespace App\Support;

use App\Services\QuoteMailService;

/**
 * Default templates (Quote PDF, Invoice PDF, Email templates) no ek j source.
 *
 *  - App install vakhate aa defaults DB ma save thay chhe (ShopDefaultsService).
 *  - Merchant e template delete / revert kare to pan fallback tarike aa j vaprai chhe.
 */
class DefaultTemplates
{
    /* ------------------------------------------------------------------
     |  Quote PDF  (shop_settings.extra_settings.quote_pdf_template)
     * ------------------------------------------------------------------ */
    public static function quotePdf(): array
    {
        return [
            'style' => [
                'showLogo' => true, 'logo' => '', 'logoSize' => 80,
                'headerFont' => 'Default', 'headerSize' => 22,
                'contentFont' => 'Default', 'contentSize' => 14,
                'primary' => '#161616', 'background' => '#FFFFFF', 'text' => '#161616',
            ],
            'content' => [
                'quotation' => [
                    'number' => true, 'createdDate' => true, 'dueDate' => false, 'dateFormat' => 'MM/DD/YYYY',
                    'discount' => true, 'shipping' => true, 'tax' => true, 'subtotal' => true,
                    'grandTotal' => true, 'salesperson' => true,
                ],
                'customer' => [
                    'name' => true, 'email' => true, 'phone' => true,
                    'billing' => true, 'shipping' => true, 'paymentTerms' => true,
                ],
                'product' => ['image' => true, 'sku' => true, 'variant' => true, 'discount' => true],
                'store' => ['name' => true, 'phone' => true, 'address' => true],
            ],
            'translation' => [
                'autoDetect' => false,
                'active' => 'English',
                'languages' => [[
                    'name' => 'English',
                    'isDefault' => true,
                    'labels' => self::quotePdfLabels(),
                ]],
            ],
        ];
    }

    public static function quotePdfLabels(): array
    {
        return [
            'quotation' => 'QUOTATION', 'quoteFrom' => 'QUOTE FROM', 'quoteTo' => 'QUOTE TO',
            'quoteNo' => 'Quote No.', 'createdDate' => 'Issue date', 'dueDate' => 'Due date',
            'shipTo' => 'SHIP TO', 'billTo' => 'BILL TO', 'salesperson' => 'SALESPERSON',
            'paymentTerms' => 'PAYMENT TERMS', 'item' => 'PRODUCTS', 'qty' => 'QTY',
            'price' => 'PRICE', 'total' => 'TOTAL', 'note' => 'Note', 'subTotal' => 'Sub Total',
            'discountRate' => 'Discount Rate', 'shippingRate' => 'Shipping Rate',
            'tax' => 'GST', 'grandTotal' => 'Grand Total',
        ];
    }

    /* ------------------------------------------------------------------
     |  Invoice PDF  (shop_settings.extra_settings.invoice_pdf_template)
     * ------------------------------------------------------------------ */
    public static function invoicePdf(): array
    {
        return [
            'style' => [
                'showLogo' => true, 'logo' => '', 'logoSize' => 80,
                'headerFont' => 'Default', 'headerSize' => 22, 'contentFont' => 'Default', 'contentSize' => 14,
                'primary' => '#161616', 'background' => '#FFFFFF', 'text' => '#161616',
            ],
            'show' => [
                'header' => ['orderNumber' => true, 'orderAmount' => true, 'issueDate' => true, 'dateFormat' => 'YYYY-MM-DD'],
                'supplier' => ['name' => true, 'email' => true, 'address' => true, 'phone' => true],
                'customer' => [
                    'name' => true, 'company' => true, 'email' => true, 'phone' => true,
                    'shipping' => true, 'billing' => true, 'paymentTerms' => true, 'inquiry' => true,
                ],
                'salesperson' => ['show' => true, 'email' => true, 'phone' => true],
                'product' => ['image' => true, 'description' => true, 'sku' => true, 'variant' => true, 'discount' => true],
                'total' => ['subtotal' => true, 'discount' => true, 'shipping' => true, 'tax' => true, 'grandTotal' => true],
                'note' => ['draftOrder' => true, 'customer' => true],
                'footer' => [
                    'show' => true,
                    'text' => "Thanks for your business!\nIf you have questions, please do get in contact",
                    'storeName' => true, 'email' => true, 'address' => true,
                ],
            ],
            'sections' => [
                ['id' => 'parties', 'label' => 'Invoice from / to', 'visible' => true],
                ['id' => 'addresses', 'label' => 'Shipping / billing address', 'visible' => true],
                ['id' => 'extra', 'label' => 'Payment terms, salesperson, inquiry', 'visible' => true],
                ['id' => 'products', 'label' => 'Product table', 'visible' => true],
                ['id' => 'summary', 'label' => 'Notes & totals', 'visible' => true],
                ['id' => 'footer', 'label' => 'Footer', 'visible' => true],
            ],
            'translation' => [
                'autoDetect' => false,
                'active' => 'English',
                'languages' => [['name' => 'English', 'isDefault' => true, 'labels' => self::invoicePdfLabels()]],
            ],
        ];
    }

    public static function invoicePdfLabels(): array
    {
        return [
            'invoice' => 'Invoice', 'orderNumber' => 'Order number', 'orderAmount' => 'Order amount',
            'issueDate' => 'Issue date', 'dueDate' => 'Due date', 'invoiceFrom' => 'Invoice from',
            'invoiceTo' => 'Invoice to', 'shippingAddress' => 'Shipping address',
            'billingAddress' => 'Billing address', 'paymentTerms' => 'Payment terms',
            'salesperson' => 'Salesperson', 'inquiry' => 'Quote inquiry info', 'item' => 'Item Description',
            'qty' => 'Qty', 'price' => 'Price', 'total' => 'Total', 'note' => 'Note',
            'subtotal' => 'Subtotal', 'discount' => 'Discount', 'shipping' => 'Shipping',
            'tax' => 'GST', 'grandTotal' => 'Grand Total',
        ];
    }

    /* ------------------------------------------------------------------
     |  Email templates  (email_templates table, ek row per type)
     * ------------------------------------------------------------------ */

    /** @return array<string, array{0: string, 1: string}>  type => [subject, body_html] */
    public static function emails(): array
    {
        return [
            QuoteMailService::TYPE_NEW_QUOTE_TO_MERCHANT => [
                'New quote request: {{quote_number}}',
                '<p>You received a new quote request <strong>{{quote_number}}</strong> from {{customer_name}} ({{customer_email}}).</p>'
                . '<p>{{quote_items_count}} item(s) - estimated total {{quote_total}}.</p>'
                . '<p>Log in to your admin panel to review and respond.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_RECEIVED_TO_CUSTOMER => [
                'We received your quote request - {{quote_number}}',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Thanks for your quote request <strong>{{quote_number}}</strong> to {{store_name}}. We will get back to you shortly with pricing.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_APPROVED => [
                'Your quote {{quote_number}} has been approved',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Good news - your quote <strong>{{quote_number}}</strong> has been approved. Final total: {{quote_total}}.</p>'
                . '<p>We will follow up shortly with next steps to complete your order.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_REJECTED => [
                'Update on your quote {{quote_number}}',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Unfortunately we are unable to fulfill quote <strong>{{quote_number}}</strong> at this time. Feel free to reach out with any questions.</p>',
            ],
            QuoteMailService::TYPE_NEW_MESSAGE => [
                'New message on quote {{quote_number}}',
                '<p>There is a new message on quote <strong>{{quote_number}}</strong>. Log in to view and reply.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_CONVERTED => [
                'Your order is ready - quote {{quote_number}}',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Your quote <strong>{{quote_number}}</strong> has been converted into an order. Total: {{quote_total}}.</p>'
                . '<p>You will receive a separate invoice/checkout link to complete payment.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_EXPIRING_SOON => [
                'Your quote {{quote_number}} is expiring soon',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Just a reminder that your quote <strong>{{quote_number}}</strong> will expire soon. Reach out if you would like to proceed.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_PROPOSAL => [
                'Your quote {{quote_number}} from {{store_name}}',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Please find your quote <strong>{{quote_number}}</strong> attached. Total: {{quote_total}}.</p>'
                . '{{custom_message}}'
                . '<p>Reply to this email if you would like to proceed or need any changes.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_UPDATED => [
                'Your quote {{quote_number}} has been updated',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Your quote <strong>{{quote_number}}</strong> with {{store_name}} has been updated. New total: {{quote_total}}.</p>'
                . '<p>Reply to this email if you have any questions or would like to proceed.</p>',
            ],
            QuoteMailService::TYPE_QUOTE_CANCELLED => [
                'Your quote {{quote_number}} has been cancelled',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Your quote <strong>{{quote_number}}</strong> with {{store_name}} has been cancelled. Feel free to reach out if you would like a new quote.</p>',
            ],
            // Invoice PDF attach thay chhe. {{payment_section}} = Shopify checkout hoy to "Pay now" link, nahi to khali.
            QuoteMailService::TYPE_INVOICE_SENT => [
                'Invoice {{invoice_number}} from {{store_name}}',
                '<p>Hi {{customer_name}},</p>'
                . '<p>Please find your invoice <strong>{{invoice_number}}</strong> (quote {{quote_number}}) attached. Amount due: {{quote_total}}.</p>'
                . '{{custom_message}}'
                . '{{payment_section}}'
                . '<p>Reply to this email if you have any questions.</p>',
            ],
        ];
    }

    /** @return array{0: string, 1: string}  [subject, body_html] */
    public static function email(string $type): array
    {
        return self::emails()[$type] ?? [
            'Notification from {{store_name}}',
            '<p>You have a new notification regarding quote {{quote_number}}.</p>',
        ];
    }
}
