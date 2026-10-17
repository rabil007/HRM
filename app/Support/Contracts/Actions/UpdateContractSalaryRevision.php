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

        $this->assertEditable->assertCanMutate($revision);

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

            $conflictingPayrollMonth = ContractSalaryRevision::query()
                ->where('company_id', $contract->company_id)
                ->where('contract_id', $contract->id)
                ->where('apply_to_payroll', true)
                ->whereDate('payroll_effective_from', $payrollEffectiveDate)
                ->whereKeyNot($revision->id)
                ->exists();

            if ($conflictingPayrollMonth) {
                throw ValidationException::withMessages([
                    'payroll_effective_from' => 'Another payroll-applicable salary revision already uses this payroll effective month.',
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
            $revision->update([
                'effective_from' => $effectiveDate,
                'apply_to_payroll' => $applyToPayroll,
                'payroll_effective_from' => $payrollEffectiveDate,
                'reason' => $reason,
            ]);

            ContractSalaryRevisionLine::query()
                ->where('revision_id', $revision->id)
                ->delete();

            foreach ($lines as $line) {
                ContractSalaryRevisionLine::query()->create([
                    'company_id' => $contract->company_id,
                    'revision_id' => $revision->id,
                    ...$line,
                ]);
            }

            $this->mirrorLatestRevision->handle($contract->fresh());

            return $revision->fresh(['lines']);
        });
    }
}
