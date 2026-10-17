<?php

namespace App\Support\Contracts\Actions;

use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Contracts\AssertContractSalaryRevisionEditable;
use App\Support\Contracts\SalaryRevisionEffectiveMonth;
use App\Support\Payroll\ContractSalaryComponentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
        private readonly AssertContractSalaryRevisionEditable $assertEditable,
    ) {}

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     */
    public function handle(
        EmployeeContract $contract,
        ContractSalaryRevision $revision,
        array $amountsByColumn,
        string $effectiveFrom,
        ?string $reason = null,
        bool $applyToPayroll = true,
        ?string $payrollEffectiveFrom = null,
    ): ContractSalaryRevision {
        abort_unless(
            $revision->contract_id === $contract->id
            && $revision->company_id === $contract->company_id,
            404,
        );

        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();
        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);
        $lines = [];

        foreach ($columnMap as $column => $componentCode) {
            $rawAmount = $amountsByColumn[$column] ?? null;

            if ($rawAmount === null || $rawAmount === '') {
                continue;
            }

            $amount = round((float) $rawAmount, 2);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'component_code' => $componentCode,
                'component_name' => $componentCode->label(),
                'rate_type' => $componentCode->defaultRateTypeFor($category, $structure)->value,
                'amount' => $amount,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'basic_salary' => 'At least one salary component with an amount greater than zero is required.',
            ]);
        }

        $effectiveDate = SalaryRevisionEffectiveMonth::normalize($effectiveFrom);

        if (! $applyToPayroll) {
            $payrollEffectiveDate = null;
        } else {
            $payrollEffectiveDate = $payrollEffectiveFrom !== null && trim($payrollEffectiveFrom) !== ''
                ? SalaryRevisionEffectiveMonth::normalize($payrollEffectiveFrom)
                : $effectiveDate;

            if ($payrollEffectiveDate < $effectiveDate) {
                throw ValidationException::withMessages([
                    'payroll_effective_from' => 'Payroll effective month cannot precede the revision effective month.',
                ]);
            }
        }

        return DB::transaction(function () use (
            $contract,
            $revision,
            $effectiveDate,
            $reason,
            $lines,
            $applyToPayroll,
            $payrollEffectiveDate,
        ): ContractSalaryRevision {
            $lockedContract = EmployeeContract::query()
                ->whereKey($contract->id)
                ->where('company_id', $contract->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRevision = ContractSalaryRevision::query()
                ->whereKey($revision->id)
                ->where('company_id', $lockedContract->company_id)
                ->where('contract_id', $lockedContract->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($applyToPayroll && $payrollEffectiveDate !== null) {
                $conflictingPayrollMonth = ContractSalaryRevision::query()
                    ->where('company_id', $lockedContract->company_id)
                    ->where('contract_id', $lockedContract->id)
                    ->where('apply_to_payroll', true)
                    ->whereDate('payroll_effective_from', $payrollEffectiveDate)
                    ->whereKeyNot($lockedRevision->id)
                    ->exists();

                if ($conflictingPayrollMonth) {
                    throw ValidationException::withMessages([
                        'payroll_effective_from' => 'Another payroll-applicable salary revision already uses this payroll effective month.',
                    ]);
                }
            }

            $this->assertEditable->assertUpdateWouldNotAlterFinalizedPayroll(
                $lockedContract,
                $lockedRevision,
                $applyToPayroll,
                $payrollEffectiveDate,
                $lines,
                $effectiveDate,
            );

            $lockedRevision->update([
                'effective_from' => $effectiveDate,
                'apply_to_payroll' => $applyToPayroll,
                'payroll_effective_from' => $payrollEffectiveDate,
                'reason' => $reason,
            ]);

            ContractSalaryRevisionLine::query()
                ->where('revision_id', $lockedRevision->id)
                ->delete();

            foreach ($lines as $line) {
                ContractSalaryRevisionLine::query()->create([
                    'company_id' => $lockedContract->company_id,
                    'revision_id' => $lockedRevision->id,
                    ...$line,
                ]);
            }

            $this->mirrorLatestRevision->handle($lockedContract->fresh());

            return $lockedRevision->fresh(['lines']);
        });
    }
}
