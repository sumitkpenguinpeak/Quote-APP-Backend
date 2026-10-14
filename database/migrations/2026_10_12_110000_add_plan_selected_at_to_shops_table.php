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
            // Merchant e install pachhi pricing page par plan select karyo tyare set thay chhe.
            // NULL = haju plan select nathi karyo -> frontend pricing page par j rakhe chhe.
            $table->timestamp('plan_selected_at')->nullable()->after('plan');
        });

        // Already install thayela shops ne pricing page par pachha na moklo.
        DB::table('shops')->where('is_active', true)->update(['plan_selected_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('plan_selected_at');
        });
    }
};
