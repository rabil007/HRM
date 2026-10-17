<?php

namespace App\Support\Contracts\Actions;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Contracts\AssertContractSalaryRevisionEditable;
use App\Support\Contracts\SalaryRevisionEffectiveMonth;
use App\Support\Payroll\ContractSalaryComponentCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
        private readonly AssertContractSalaryRevisionEditable $assertEditable,
    ) {}

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     * @param  array<string, float|int|string|null>|null  $historicalBaselineAmounts
     */
    public function handle(
        EmployeeContract $contract,
        array $amountsByColumn,
        string $effectiveFrom,
        ?string $reason = null,
        ?int $createdBy = null,
        bool $applyToPayroll = true,
        ?string $payrollEffectiveFrom = null,
        ?array $historicalBaselineAmounts = null,
    ): ContractSalaryRevision {
        $lines = $this->linesFromAmounts($contract, $amountsByColumn);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'basic_salary' => 'At least one salary component with an amount greater than zero is required.',
            ]);
        }

        $effectiveDate = SalaryRevisionEffectiveMonth::normalize($effectiveFrom);
        [$applyToPayroll, $payrollEffectiveDate] = $this->normalizePayrollApplication(
            $applyToPayroll,
            $payrollEffectiveFrom,
            $effectiveDate,
        );

        return DB::transaction(function () use (
            $contract,
            $effectiveDate,
            $reason,
            $createdBy,
            $lines,
            $applyToPayroll,
            $payrollEffectiveDate,
            $historicalBaselineAmounts,
        ): ContractSalaryRevision {
            $lockedContract = EmployeeContract::query()
                ->whereKey($contract->id)
                ->where('company_id', $contract->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $revisionAlreadyExists = ContractSalaryRevision::query()
                ->where('company_id', $lockedContract->company_id)
                ->where('contract_id', $lockedContract->id)
                ->whereDate('effective_from', $effectiveDate)
                ->exists();

            if ($revisionAlreadyExists) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A salary revision already exists for this month.',
                ]);
            }

            if ($applyToPayroll && $payrollEffectiveDate !== null) {
                $this->assertUniquePayrollEffectiveMonth(
                    $lockedContract,
                    $payrollEffectiveDate,
                );
            }

            $hasRevisionHistory = ContractSalaryRevision::query()
                ->withTrashed()
                ->where('company_id', $lockedContract->company_id)
                ->where('contract_id', $lockedContract->id)
                ->exists();

            $baselineDate = $lockedContract->start_date !== null
                ? SalaryRevisionEffectiveMonth::normalize($lockedContract->start_date->toDateString())
                : null;

            if (! $hasRevisionHistory && $baselineDate !== null && $baselineDate < $effectiveDate) {
                // Contract updates persist the new package before the revision is
                // recorded. Callers that already overwrote the row must pass the
                // previous package, or the baseline would copy the new salary
                // back to the contract start and rewrite historical payroll.
                $baselineSourceAmounts = $historicalBaselineAmounts
                    ?? self::amountsFromContract($lockedContract);
                $baselineLines = $historicalBaselineAmounts !== null
                    ? $this->linesFromHistoricalBaselineAmounts($lockedContract, $baselineSourceAmounts)
                    : $this->linesFromAmounts($lockedContract, $baselineSourceAmounts);

                if ($baselineLines !== []) {
                    $this->createRevision(
                        $lockedContract,
                        $baselineLines,
                        $baselineDate,
                        'Historical contract salary baseline',
                        $createdBy,
                        true,
                        $baselineDate,
                    );
                }
            }

            $nextVersion = (int) ContractSalaryRevision::query()
                ->withTrashed()
                ->where('company_id', $lockedContract->company_id)
                ->where('contract_id', $lockedContract->id)
                ->max('version') + 1;

            $this->assertEditable->assertCreateWouldNotAlterFinalizedPayroll(
                $lockedContract,
                $applyToPayroll,
                $payrollEffectiveDate,
                $lines,
                $nextVersion,
            );

            $revision = $this->createRevision(
                $lockedContract,
                $lines,
                $effectiveDate,
                $reason,
                $createdBy,
                $applyToPayroll,
                $payrollEffectiveDate,
            );

            $this->mirrorLatestRevision->handle($lockedContract->fresh());

            return $revision->load('lines');
        });
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function normalizePayrollApplication(
        bool $applyToPayroll,
        ?string $payrollEffectiveFrom,
        string $effectiveDate,
    ): array {
        if (! $applyToPayroll) {
            return [false, null];
        }

        $payrollEffectiveDate = $payrollEffectiveFrom !== null && trim($payrollEffectiveFrom) !== ''
            ? SalaryRevisionEffectiveMonth::normalize($payrollEffectiveFrom)
            : $effectiveDate;

        if ($payrollEffectiveDate < $effectiveDate) {
            throw ValidationException::withMessages([
                'payroll_effective_from' => 'Payroll effective month cannot precede the revision effective month.',
            ]);
        }

        return [true, $payrollEffectiveDate];
    }

    private function assertUniquePayrollEffectiveMonth(
        EmployeeContract $contract,
        string $payrollEffectiveDate,
        ?int $ignoreRevisionId = null,
    ): void {
        $exists = ContractSalaryRevision::query()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->where('apply_to_payroll', true)
            ->whereDate('payroll_effective_from', $payrollEffectiveDate)
            ->when(
                $ignoreRevisionId !== null,
                fn ($query) => $query->whereKeyNot($ignoreRevisionId),
            )
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'payroll_effective_from' => 'Another payroll-applicable salary revision already uses this payroll effective month.',
            ]);
        }
    }

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     * @return list<array{component_code: SalaryComponentCode, component_name: string, rate_type: string, amount: float}>
     */
    private function linesFromAmounts(EmployeeContract $contract, array $amountsByColumn): array
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();

        return $this->linesFromColumnMap(
            $category,
            $structure,
            ContractSalaryComponentCatalog::legacyColumnMap($category, $structure),
            $amountsByColumn,
        );
    }

    /**
     * Build baseline lines from a pre-update package. Uses the union of Crew
     * monthly/daily column maps so a live structure change cannot drop
     * historical housing/EOS/site components from the preserved baseline.
     *
     * @param  array<string, float|int|string|null>  $amountsByColumn
     * @return list<array{component_code: SalaryComponentCode, component_name: string, rate_type: string, amount: float}>
     */
    private function linesFromHistoricalBaselineAmounts(
        EmployeeContract $contract,
        array $amountsByColumn,
    ): array {
        $category = $contract->payroll_category ?? PayrollCategory::Office;

        if ($category !== PayrollCategory::Crew) {
            return $this->linesFromAmounts($contract, $amountsByColumn);
        }

        $monthlyStructure = ContractSalaryStructure::Monthly;
        $dailyStructure = ContractSalaryStructure::Daily;
        $monthlyMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $monthlyStructure);
        $dailyMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $dailyStructure);

        $monthlyHits = $this->positiveColumnHits($amountsByColumn, $monthlyMap);
        $dailyHits = $this->positiveColumnHits($amountsByColumn, $dailyMap);
        $structure = $dailyHits > $monthlyHits ? $dailyStructure : $monthlyStructure;
        // Union of maps so a live structure change cannot drop historical
        // housing/EOS/site components that still exist on the prior package.
        $columnMap = array_merge($dailyMap, $monthlyMap);

        return $this->linesFromColumnMap($category, $structure, $columnMap, $amountsByColumn);
    }

    /**
     * @param  array<string, SalaryComponentCode>  $columnMap
     * @param  array<string, float|int|string|null>  $amountsByColumn
     * @return list<array{component_code: SalaryComponentCode, component_name: string, rate_type: string, amount: float}>
     */
    private function linesFromColumnMap(
        PayrollCategory $category,
        ContractSalaryStructure $structure,
        array $columnMap,
        array $amountsByColumn,
    ): array {
        $lines = [];
        $seenCodes = [];

        foreach ($columnMap as $column => $componentCode) {
            $codeKey = $componentCode->value;

            if (isset($seenCodes[$codeKey])) {
                continue;
            }

            $rawAmount = $amountsByColumn[$column] ?? null;

            if ($rawAmount === null || $rawAmount === '') {
                continue;
            }

            $amount = round((float) $rawAmount, 2);

            if ($amount <= 0) {
                continue;
            }

            $seenCodes[$codeKey] = true;
            $lines[] = [
                'component_code' => $componentCode,
                'component_name' => $componentCode->label(),
                'rate_type' => $componentCode->defaultRateTypeFor($category, $structure)->value,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, float|int|string|null>  $amountsByColumn
     * @param  array<string, SalaryComponentCode>  $columnMap
     */
    private function positiveColumnHits(array $amountsByColumn, array $columnMap): int
    {
        $hits = 0;

        foreach (array_keys($columnMap) as $column) {
            $rawAmount = $amountsByColumn[$column] ?? null;

            if ($rawAmount === null || $rawAmount === '') {
                continue;
            }

            if (round((float) $rawAmount, 2) > 0) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  list<array{component_code: SalaryComponentCode, component_name: string, rate_type: string, amount: float}>  $lines
     */
    private function createRevision(
        EmployeeContract $contract,
        array $lines,
        string $effectiveFrom,
        ?string $reason,
        ?int $createdBy,
        bool $applyToPayroll,
        ?string $payrollEffectiveFrom,
    ): ContractSalaryRevision {
        $nextVersion = (int) ContractSalaryRevision::query()
            ->withTrashed()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->max('version') + 1;

        $revision = ContractSalaryRevision::query()->create([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'version' => $nextVersion,
            'effective_from' => $effectiveFrom,
            'apply_to_payroll' => $applyToPayroll,
            'payroll_effective_from' => $payrollEffectiveFrom,
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

        return $revision;
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
