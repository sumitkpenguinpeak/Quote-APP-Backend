<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id(); // bigint unsigned AUTO_INCREMENT

            $table->string('product_id');
            $table->string('product_handle');
            $table->string('name');
            $table->string('email');
            $table->string('rating');
            $table->text('review_title');
            $table->text('review_body');
            $table->enum('status', ['approved', 'pending', 'rejected'])->default('pending');
            $table->text('review_file')->nullable();
            $table->boolean('recommend')->default(0);

            $table->unsignedBigInteger('shop_id');

            $table->timestamps();

            // Optional Foreign Key
            $table->foreign('shop_id')
                  ->references('id')
                  ->on('shops')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};