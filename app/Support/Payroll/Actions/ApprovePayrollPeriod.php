<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\AssertCrewPayrollCalculationFreshness;
use App\Support\Payroll\AssertPayrollAllocationLifecycle;
use App\Support\Payroll\PersistPayrollWorkAllocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovePayrollPeriod
{
    public function handle(PayrollPeriod $period, User $approver): PayrollPeriod
    {
        if (! $period->canApprove()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only processing pay periods with generated payroll records can be approved.',
            ]);
        }

        $approvedPeriod = DB::transaction(function () use ($period, $approver): PayrollPeriod {
            app(AssertCrewPayrollCalculationFreshness::class)->assertFreshForApprove($period);

            $period->payrollRecords()->update(['status' => 'approved']);

            app(PersistPayrollWorkAllocations::class)->transitionToApproved($period);

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            app(AssertPayrollAllocationLifecycle::class)->assertAfterApprove($period->fresh() ?? $period);

            return $period->refresh();
        });

        app(GeneratePayrollPayslips::class)->dispatchForPeriod($approvedPeriod);

        return $approvedPeriod;
    }
}
