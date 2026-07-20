<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->string('crew_timesheet_mode')->nullable()->after('payroll_category');
        });

        DB::table('payroll_periods')
            ->where('payroll_category', 'crew')
            ->whereNull('crew_timesheet_mode')
            ->update(['crew_timesheet_mode' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('crew_timesheet_mode');
        });
    }
};
