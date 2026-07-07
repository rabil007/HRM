<?php

namespace App\Support\Payroll\Actions;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use Illuminate\Validation\ValidationException;

final class SyncEmployeeSalaryInputsFromImport
{
    /**
     * Sync salary inputs from import. Typed columns listed in $managedTypeIds with blank/zero
     * amounts are removed; positive amounts are upserted. Flat additions/deductions from the
     * timesheet columns are mirrored into bonus/other salary input types when no typed value exists.
     *
     * @param  array<int, float|null>  $amountsByTypeId
     * @param  list<int>  $managedTypeIds
     * @return array{mirrored_addition: bool, mirrored_deduction: bool}
     */
    public function handle(
        PayrollPeriod $period,
        Employee $employee,
        array $amountsByTypeId,
        array $managedTypeIds,
        float $flatAdditionalAmount = 0,
        float $flatDeductionAmount = 0,
    ): array {
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

        $mirroredAddition = false;
        $mirroredDeduction = false;

        $bonusTypeId = $this->resolveTypeId($period->company_id, 'bonus', isAddition: true);
        $otherTypeId = $this->resolveTypeId($period->company_id, 'other', isAddition: false);

        if ($flatAdditionalAmount > 0 && $bonusTypeId !== null) {
            $typedBonusAmount = $amountsByTypeId[$bonusTypeId] ?? null;

            if ($typedBonusAmount === null || $typedBonusAmount <= 0) {
                $this->syncTypeAmount($period, $employee, $bonusTypeId, $flatAdditionalAmount);
                $mirroredAddition = true;
            }
        }

        if ($flatDeductionAmount > 0 && $otherTypeId !== null) {
            $typedDeductionAmount = $amountsByTypeId[$otherTypeId] ?? null;

            if ($typedDeductionAmount === null || $typedDeductionAmount <= 0) {
                $this->syncTypeAmount($period, $employee, $otherTypeId, $flatDeductionAmount);
                $mirroredDeduction = true;
            }
        }

        return [
            'mirrored_addition' => $mirroredAddition,
            'mirrored_deduction' => $mirroredDeduction,
        ];
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

    private function resolveTypeId(int $companyId, string $code, bool $isAddition): ?int
    {
        (new ProvisionDefaultSalaryInputTypes)->handle($companyId);

        $typeId = SalaryInputType::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('status', 'active')
            ->value('id');

        if ($typeId !== null) {
            return (int) $typeId;
        }

        $fallbackId = SalaryInputType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_addition', $isAddition)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');

        return $fallbackId !== null ? (int) $fallbackId : null;
    }
}
