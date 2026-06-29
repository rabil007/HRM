<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevertPayrollPeriodToDraft
{
    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canRevertToDraft()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only processing or approved pay periods can be reverted to draft.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $period->payrollRecords()->delete();
            $period->salaryInputs()->delete();

            $period->update([
                'status' => PayrollPeriodStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
                'excluded_employee_ids' => null,
            ]);

            return $period->refresh();
        });
    }
}
