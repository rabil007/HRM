<?php

use App\Enums\LeaveTypePayrollTreatment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correct soft-deleted legacy unpaid leave types skipped by the original
 * payroll_treatment backfill (which filtered whereNull('deleted_at')).
 *
 * Only soft-deleted rows with legacy unpaid codes UL / UNPAID / LOP are updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_types') || ! Schema::hasColumn('leave_types', 'payroll_treatment')) {
            return;
        }

        $unpaidCodes = LeaveTypePayrollTreatment::legacyUnpaidCodes();

        DB::table('leave_types')
            ->whereNotNull('deleted_at')
            ->where(function ($query) use ($unpaidCodes): void {
                foreach ($unpaidCodes as $index => $code) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}('UPPER(code) = ?', [$code]);
                }
            })
            ->update(['payroll_treatment' => LeaveTypePayrollTreatment::Unpaid->value]);
    }

    public function down(): void
    {
        // Irreversible data correction: prior paid/unpaid values for soft-deleted
        // legacy codes are not recoverable without a backup.
    }
};
