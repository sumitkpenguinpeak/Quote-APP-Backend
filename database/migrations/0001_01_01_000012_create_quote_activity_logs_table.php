<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();

            $table->string('action'); // created | status_changed | price_offered | assigned | converted | note_added ...
            $table->string('performed_by_type')->default('system'); // staff | customer | system
            $table->foreignId('performed_by_staff_id')->nullable()->constrained('staff_members')->nullOnDelete();

            $table->text('description')->nullable();
            $table->json('meta')->nullable(); // e.g. {"from":"pending","to":"approved"}

            $table->timestamps();

            $table->index(['quote_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_activity_logs');
    }
};
