<?php

use App\Enums\LeaveTypePayrollTreatment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('payroll_treatment', 20)
                ->default(LeaveTypePayrollTreatment::Paid->value)
                ->after('status');
        });

        $unpaidCodes = LeaveTypePayrollTreatment::legacyUnpaidCodes();

        DB::table('leave_types')
            ->whereNull('deleted_at')
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
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('payroll_treatment');
        });
    }
};
