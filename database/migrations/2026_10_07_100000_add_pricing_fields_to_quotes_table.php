<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add discount / Add shipping / Add Tax Rate popups ni values quote par save karva mate.
 * Dar ek column pehla check thay chhe (hasColumn), etle fari chalavo to pan error nahi aave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $add = fn (string $col, callable $define) => Schema::hasColumn('quotes', $col) ?: $define($table);

            // ---- discount (discount_type / discount_value pehla thi chhe) ----
            $add('discount_amount', fn ($t) => $t->decimal('discount_amount', 12, 2)->default(0));
            $add('discount_reason', fn ($t) => $t->string('discount_reason')->nullable());
            $add('discount_show_on_draft_order', fn ($t) => $t->boolean('discount_show_on_draft_order')->default(false));
            $add('discount_show_on_email', fn ($t) => $t->boolean('discount_show_on_email')->default(false));
            $add('discount_show_on_invoice_pdf', fn ($t) => $t->boolean('discount_show_on_invoice_pdf')->default(false));
            $add('discount_show_on_pdf', fn ($t) => $t->boolean('discount_show_on_pdf')->default(false));

            // ---- shipping ----
            $add('shipping_price', fn ($t) => $t->decimal('shipping_price', 12, 2)->default(0));
            $add('shipping_name', fn ($t) => $t->string('shipping_name')->nullable());

            // ---- tax ----
            $add('tax_type', fn ($t) => $t->string('tax_type')->nullable()); // percentage | fixed
            $add('tax_value', fn ($t) => $t->decimal('tax_value', 12, 2)->default(0));
            $add('tax_amount', fn ($t) => $t->decimal('tax_amount', 12, 2)->default(0));
            $add('tax_name', fn ($t) => $t->string('tax_name', 15)->nullable());

            // ---- Due date (right side card) ----
            $add('due_date', fn ($t) => $t->date('due_date')->nullable());
        });
    }

    public function down(): void
    {
        $cols = [
            'discount_amount', 'discount_reason', 'discount_show_on_draft_order', 'discount_show_on_email',
            'discount_show_on_invoice_pdf', 'discount_show_on_pdf', 'shipping_price', 'shipping_name',
            'tax_type', 'tax_value', 'tax_amount', 'tax_name', 'due_date',
        ];

        Schema::table('quotes', function (Blueprint $table) use ($cols) {
            $existing = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('quotes', $c)));
            if ($existing) {
                $table->dropColumn($existing);
            }
        });
    }
};