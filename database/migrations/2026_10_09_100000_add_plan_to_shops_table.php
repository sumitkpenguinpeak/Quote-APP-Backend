<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Plan key from config('shopify.plans'): free | starter | pro
            $table->string('plan')->default('free')->after('billing_id');
            $table->index('plan');
        });

        // Merchants already paying for the old single "Review Pro Basic" plan
        // ($9.99) keep their price, which is now the Starter plan.
        DB::table('shops')->where('billing_status', 'active')->update(['plan' => 'starter']);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex(['plan']);
            $table->dropColumn('plan');
        });
    }
};
