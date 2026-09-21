<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->timestamp('rollover_applied_at')->nullable()->after('carried_days');
        });

        // Closed prior years already went through annual rollover. Mark them so
        // re-runs do not rewrite historical carry. Current and future years stay
        // null so provisional future-year balances can still receive carry-forward.
        $currentYear = (int) now()->year;

        DB::table('leave_balances')
            ->whereNull('rollover_applied_at')
            ->whereNull('deleted_at')
            ->where('year', '<', $currentYear)
            ->update(['rollover_applied_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->dropColumn('rollover_applied_at');
        });
    }
};
