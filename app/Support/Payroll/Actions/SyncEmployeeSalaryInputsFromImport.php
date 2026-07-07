<?php

namespace App\Support\Payroll\Actions;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use Illuminate\Validation\ValidationException;

final class SyncEmployeeSalaryInputsFromImport
{
    /**
     * Sync salary inputs from import. Typed columns listed in $managedTypeIds with blank/zero
     * amounts are removed; positive amounts are upserted.
     *
     * @param  array<int, float|null>  $amountsByTypeId
     * @param  list<int>  $managedTypeIds
     */
    public function handle(
        PayrollPeriod $period,
        Employee $employee,
        array $amountsByTypeId,
        array $managedTypeIds,
    ): void {
        if (! $period->canGeneratePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Salary inputs can only be managed for draft or processing periods.',
            ]);
        }

        foreach ($managedTypeIds as $typeId) {
            $this->syncTypeAmount(
                $period,
                $employee,
                $typeId,
                $amountsByTypeId[$typeId] ?? null,
            );
        }
    }

    private function syncTypeAmount(
        PayrollPeriod $period,
        Employee $employee,
        int $typeId,
        ?float $amount,
    ): void {
        if ($amount === null || $amount <= 0) {
            SalaryInput::query()
                ->where('company_id', $period->company_id)
                ->where('employee_id', $employee->id)
                ->where('period_id', $period->id)
                ->where('salary_input_type_id', $typeId)
                ->delete();

            return;
        }

        SalaryInput::query()->updateOrCreate(
            [
                'company_id' => $period->company_id,
                'employee_id' => $employee->id,
                'period_id' => $period->id,
                'salary_input_type_id' => $typeId,
            ],
            [
                'amount' => round($amount, 2),
                'notes' => null,
            ],
        );
    }
}
