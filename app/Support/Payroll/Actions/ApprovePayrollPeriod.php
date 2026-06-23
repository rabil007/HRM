<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\User;
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

        return DB::transaction(function () use ($period, $approver): PayrollPeriod {
            $period->payrollRecords()->update(['status' => 'approved']);

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $period->refresh();
        });
    }
}
