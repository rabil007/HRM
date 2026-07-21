<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destructive cleanup approved before any production payroll data exists.
 *
 * Removes the legacy generic standby columns and introduces an explicit
 * Monthly crew unpaid-leave field. Daily crew now relies solely on the split
 * sign-on / onsite / sign-off operational columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crew_timesheets', 'unpaid_leave_days')) {
            Schema::table('crew_timesheets', function (Blueprint $table): void {
                $table->decimal('unpaid_leave_days', 8, 2)->nullable()->after('sign_off_standby_days');
            });
        }

        Schema::table('crew_timesheets', function (Blueprint $table): void {
            foreach (['standby_from', 'standby_to', 'standby_days'] as $column) {
                if (Schema::hasColumn('crew_timesheets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('crew_timesheets', function (Blueprint $table): void {
            if (! Schema::hasColumn('crew_timesheets', 'standby_from')) {
                $table->date('standby_from')->nullable()->after('period_id');
            }

            if (! Schema::hasColumn('crew_timesheets', 'standby_to')) {
                $table->date('standby_to')->nullable()->after('standby_from');
            }

            if (! Schema::hasColumn('crew_timesheets', 'standby_days')) {
                $table->decimal('standby_days', 8, 2)->nullable()->after('standby_to');
            }
        });

        Schema::table('crew_timesheets', function (Blueprint $table): void {
            if (Schema::hasColumn('crew_timesheets', 'unpaid_leave_days')) {
                $table->dropColumn('unpaid_leave_days');
            }
        });
    }
};
