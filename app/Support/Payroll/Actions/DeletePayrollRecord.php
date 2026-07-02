<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DeletePayrollRecord
{
    public function handle(PayrollPeriod $period, PayrollRecord $record): void
    {
        abort_unless(
            (int) $record->company_id === (int) $period->company_id
            && (int) $record->period_id === (int) $period->id,
            404,
        );

        if (! $period->canGeneratePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Payroll records can only be removed while the pay run is in draft or processing.',
            ]);
        }

        DB::transaction(function () use ($period, $record): void {
            $period->salaryInputs()
                ->where('employee_id', $record->employee_id)
                ->delete();

            if (filled($record->payslip_path) && Storage::disk('local')->exists($record->payslip_path)) {
                Storage::disk('local')->delete($record->payslip_path);
            }

            $record->delete();

            $excludedEmployeeIds = array_values(array_unique(array_merge(
                $period->excluded_employee_ids ?? [],
                [(int) $record->employee_id],
            )));

            $updates = [
                'excluded_employee_ids' => $excludedEmployeeIds,
            ];

            if (! $period->payrollRecords()->exists()) {
                $updates['status'] = PayrollPeriodStatus::Draft;
                $period->salaryInputs()->delete();
            }

            $period->update($updates);
        });
    }
}
