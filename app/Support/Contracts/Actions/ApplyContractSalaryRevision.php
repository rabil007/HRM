<?php

namespace App\Support\Contracts\Actions;

use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Contracts\SalaryRevisionEffectiveMonth;
use App\Support\Payroll\ContractSalaryComponentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
    ) {}

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     */
    public function handle(
        EmployeeContract $contract,
        array $amountsByColumn,
        string $effectiveFrom,
        ?string $reason = null,
        ?int $createdBy = null,
    ): ContractSalaryRevision {
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

        return DB::transaction(function () use (
            $contract,
            $effectiveDate,
            $reason,
            $createdBy,
            $lines,
        ): ContractSalaryRevision {
            $nextVersion = (int) ContractSalaryRevision::query()
                ->where('contract_id', $contract->id)
                ->max('version') + 1;

            $revision = ContractSalaryRevision::query()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'version' => $nextVersion,
                'effective_from' => $effectiveDate,
                'reason' => $reason,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                ContractSalaryRevisionLine::query()->create([
                    'company_id' => $contract->company_id,
                    'revision_id' => $revision->id,
                    ...$line,
                ]);
            }

            $this->mirrorLatestRevision->handle($contract->fresh());

            return $revision->load('lines');
        });
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public static function amountsFromContract(EmployeeContract $contract): array
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();
        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);
        $amounts = [];

        foreach (array_keys($columnMap) as $column) {
            $amounts[$column] = $contract->{$column};
        }

        return $amounts;
    }

    /**
     * @param  array<string, float|int|string|null>  $before
     * @param  array<string, float|int|string|null>  $after
     */
    public static function salaryPackageChanged(array $before, array $after): bool
    {
        $columns = array_unique([...array_keys($before), ...array_keys($after)]);

        foreach ($columns as $column) {
            $left = self::normalizeAmount($before[$column] ?? null);
            $right = self::normalizeAmount($after[$column] ?? null);

            if ($left !== $right) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = round((float) $value, 2);

        if ($amount <= 0) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }
}
