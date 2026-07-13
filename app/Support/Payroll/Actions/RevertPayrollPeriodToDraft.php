<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class RevertPayrollPeriodToDraft
{
    public function handle(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->canRevertToDraft()) {
            throw ValidationException::withMessages([
                'period_id' => 'Only processing pay periods can be reverted to draft.',
            ]);
        }

        return DB::transaction(function () use ($period): PayrollPeriod {
            $period->payrollRecords()
                ->get()
                ->each(function (PayrollRecord $record): void {
                    if (filled($record->payslip_path) && Storage::disk('local')->exists($record->payslip_path)) {
                        Storage::disk('local')->delete($record->payslip_path);
                    }
                });

            $period->payrollRecords()->forceDelete();
            $period->salaryInputs()->forceDelete();

            if ($period->isCrew()) {
                $period->crewTimesheets()->forceDelete();
            }

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
