<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_button_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // internal label for merchant, e.g. "Wholesale items"

            // applies_to: all_products | specific_products | specific_collections | specific_tags
            $table->string('applies_to')->default('all_products');
            $table->json('target_ids')->nullable(); // shopify product/collection ids or tag strings

            $table->boolean('hide_price')->default(false);
            $table->boolean('hide_add_to_cart')->default(false);
            $table->boolean('show_quote_button')->default(true);

            $table->unsignedInteger('priority')->default(0); // higher priority wins on conflict
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_button_rules');
    }
};
