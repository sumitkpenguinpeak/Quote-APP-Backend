<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();

            $table->string('quote_number')->unique(); // e.g. Q-1001, human friendly reference

            // pending -> viewed -> negotiating -> approved -> converted / rejected / expired / cancelled
            $table->string('status')->default('pending');

            $table->string('source')->nullable(); // product_page | collection_page | cart_page
            $table->string('currency', 10)->default('USD');

            $table->decimal('subtotal', 12, 2)->default(0); // sum of original prices
            $table->string('discount_type')->nullable(); // percentage | fixed
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->default(0); // final quoted total

            $table->text('customer_note')->nullable();
            $table->text('merchant_note')->nullable(); // internal note, not visible to customer

            // Draft order / order conversion (Shopify side references)
            $table->string('shopify_draft_order_id')->nullable();
            $table->string('shopify_draft_order_gid')->nullable();
            $table->string('shopify_order_id')->nullable();
            $table->string('checkout_url')->nullable();

            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
