<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained('shops')->cascadeOnDelete();
            $table->string('provider')->default('gmail');
            $table->string('email');
            $table->text('access_token');          // encrypted via model cast
            $table->text('refresh_token');         // encrypted via model cast
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('active'); // active | reauth_required
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_email_accounts');
    }
};
