<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevertPayrollPeriodToApproved
{
    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canRevertToApproved()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only paid pay periods can be reverted to approved.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $period->payrollRecords()->update([
                'status' => 'approved',
                'paid_at' => null,
            ]);

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
            ]);

            return $period->refresh();
        });
    }
}
