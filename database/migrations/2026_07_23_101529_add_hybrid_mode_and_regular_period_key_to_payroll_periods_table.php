<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Hybrid crew timesheet mode for mixed employee-level sources and a
 * concurrency-safe regular_period_key so each company/category/month has at
 * most one normal full-month payroll period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('regular_period_key')->nullable()->after('automatic_period_key');
            $table->unique('regular_period_key');
        });

        DB::table('payroll_periods')
            ->where('payroll_category', 'crew')
            ->where('status', 'draft')
            ->whereIn('crew_timesheet_mode', ['manual', 'crew_operations'])
            ->whereNull('deleted_at')
            ->update(['crew_timesheet_mode' => 'hybrid']);

        $periods = DB::table('payroll_periods')
            ->whereNull('deleted_at')
            ->whereNull('regular_period_key')
            ->orderBy('id')
            ->get(['id', 'company_id', 'payroll_category', 'start_date', 'end_date']);

        $assigned = [];

        foreach ($periods as $period) {
            $start = (string) $period->start_date;
            $end = (string) $period->end_date;
            $monthStart = date('Y-m-01', strtotime($start));
            $monthEnd = date('Y-m-t', strtotime($start));

            if ($start !== $monthStart || $end !== $monthEnd) {
                continue;
            }

            $key = sprintf(
                'company:%d:%s:%s',
                (int) $period->company_id,
                (string) $period->payroll_category,
                date('Y-m', strtotime($start)),
            );

            if (isset($assigned[$key])) {
                continue;
            }

            DB::table('payroll_periods')
                ->where('id', $period->id)
                ->update(['regular_period_key' => $key]);

            $assigned[$key] = true;
        }
    }

    public function down(): void
    {
        DB::table('payroll_periods')
            ->where('crew_timesheet_mode', 'hybrid')
            ->update(['crew_timesheet_mode' => 'manual']);

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropUnique(['regular_period_key']);
            $table->dropColumn('regular_period_key');
        });
    }
};
