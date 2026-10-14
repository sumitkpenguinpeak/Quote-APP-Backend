<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice number (Settings > Formatting standards > "PDF invoice" format ma thi bane chhe)
 * ane invoice kyare issue thayo te quote par save karva mate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->index();
            }
            if (! Schema::hasColumn('quotes', 'invoice_issued_at')) {
                $table->timestamp('invoice_issued_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $existing = array_values(array_filter(
                ['invoice_number', 'invoice_issued_at'],
                fn ($c) => Schema::hasColumn('quotes', $c)
            ));
            if ($existing) {
                $table->dropColumn($existing);
            }
        });
    }
};
