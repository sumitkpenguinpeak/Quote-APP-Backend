<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            $table->string('sender_type'); // merchant | customer | system
            $table->foreignId('sender_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();

            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['quote_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_messages');
    }
};
