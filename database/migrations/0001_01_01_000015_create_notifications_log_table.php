<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->cascadeOnDelete();

            $table->string('channel')->default('email'); // email | sms (future)
            $table->string('type'); // matches email_templates.type
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('status')->default('pending'); // pending | sent | failed
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
    }
};
