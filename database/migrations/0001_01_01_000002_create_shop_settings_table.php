<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Button design
            $table->string('button_text')->default('Request a Quote');
            $table->string('button_color')->default('#000000');
            $table->string('button_text_color')->default('#FFFFFF');
            $table->string('button_position')->default('below_add_to_cart'); // above/below add to cart, custom
            $table->boolean('show_on_product_page')->default(true);
            $table->boolean('show_on_collection_page')->default(false);
            $table->boolean('show_on_cart_page')->default(false);

            // Price / Add to cart hiding
            $table->boolean('hide_price_globally')->default(false);
            $table->boolean('hide_add_to_cart_globally')->default(false);
            $table->string('hidden_price_replacement_text')->default('Contact us for price');

            // Quote behaviour
            $table->boolean('allow_multiple_products_per_quote')->default(true); // quote-list/cart style
            $table->boolean('allow_customer_suggest_price')->default(true);
            $table->boolean('allow_quantity_edit')->default(true);
            $table->unsignedInteger('quote_expiry_days')->default(7);
            $table->boolean('auto_create_draft_order_on_approve')->default(true);

            // Notifications
            $table->string('notification_email')->nullable(); // merchant email to notify
            $table->boolean('notify_merchant_on_new_quote')->default(true);
            $table->boolean('notify_customer_on_status_change')->default(true);

            // Misc JSON bucket for theme/appearance extras that don't need own column
            $table->json('extra_settings')->nullable();

            $table->timestamps();

            $table->unique('shop_id'); // one settings row per shop
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
