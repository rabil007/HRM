<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelPayrollPeriod
{
    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canCancel()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only draft, processing, or approved pay periods can be cancelled.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $period->payrollRecords()->delete();

            $period->update([
                'status' => PayrollPeriodStatus::Cancelled,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $period->refresh();
        });
    }
}
