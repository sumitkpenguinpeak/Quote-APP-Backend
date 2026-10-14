<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_customer_id')->nullable(); // null for guest quote requests
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // B2B / company info (auto-filled for known B2B customers)
            $table->string('company_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->json('company_details')->nullable(); // address, industry, etc.
            $table->boolean('is_b2b')->default(false);

            $table->timestamps();

            $table->index(['shop_id', 'shopify_customer_id']);
            $table->index(['shop_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
