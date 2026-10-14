<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            $table->string('shopify_product_id');
            $table->string('shopify_variant_id')->nullable();
            $table->string('product_title');
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('image_url')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('original_price', 12, 2)->default(0); // store's listed price at time of request
            $table->decimal('customer_requested_price', 12, 2)->nullable(); // price customer proposes
            $table->decimal('merchant_offered_price', 12, 2)->nullable(); // price merchant counters with
            $table->decimal('final_price', 12, 2)->nullable(); // agreed price used on draft order

            $table->string('discount_type')->nullable(); // percentage | fixed
            $table->decimal('discount_value', 12, 2)->nullable();

            $table->decimal('line_total', 12, 2)->default(0);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('quote_id');
            $table->index(['shopify_product_id', 'shopify_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
    }
};
