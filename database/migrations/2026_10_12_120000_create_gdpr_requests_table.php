<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of privacy requests. Holds no customer PII (only Shopify's numeric
        // customer id and the path of the private export file).
        Schema::create('gdpr_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable()->index(); // no FK: survives shop/redact
            $table->string('shop')->index();
            $table->string('type'); // customers/data_request | customers/redact | shop/redact
            $table->string('shopify_customer_id')->nullable();
            $table->string('export_path')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_requests');
    }
};
