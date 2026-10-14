<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // e.g. "Phone Number", "Preferred delivery date"
            $table->string('key')->nullable(); // machine name, e.g. phone_number
            $table->string('field_type')->default('text');
            // field_type: text | textarea | email | phone | number | select | checkbox | radio | file | date
            $table->json('options')->nullable(); // choices for select/radio/checkbox
            $table->string('placeholder')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_default')->default(false); // built-in fields like name/email that can't be deleted
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_form_fields');
    }
};
