<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MarkPayrollPeriodPaid
{
    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canMarkPaid()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only approved pay periods with payroll records can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $paidAt = now();

            $period->payrollRecords()->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
            ]);

            $period->update([
                'status' => PayrollPeriodStatus::Paid,
            ]);

            return $period->refresh();
        });
    }
}
