<?php

namespace App\Support\Contracts;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Blocks in-place payroll category / salary structure changes that would rewrite
 * how historical contract periods are classified. Ordinary salary amount changes
 * within the same category and structure are not gated here.
 */
final class AssertContractSalaryStructureChangeAllowed
{
    public const PAYROLL_HISTORY_MESSAGE = 'This contract already has payroll history. Its payroll category or salary structure cannot be changed directly. Create a new effective-dated contract to preserve historical payroll.';

    public const REVISION_HISTORY_MESSAGE = 'This contract already has salary revision history. Its payroll category or salary structure cannot be changed directly.';

    public const LATER_MONTH_MESSAGE = 'This contract\'s payroll category or salary structure cannot be changed for a later month in place. End this contract and create a new effective-dated contract to preserve historical periods.';

    public const AMBIGUOUS_EFFECTIVE_MONTH_MESSAGE = 'Cannot determine the effective month for this payroll category or salary structure change. Provide a revision effective month that matches the contract start month for an initial correction, or create a new effective-dated contract.';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assert(
        EmployeeContract $existing,
        array $attributes,
        ?string $intendedEffectiveFrom = null,
    ): void {
        $beforeCategory = $existing->payroll_category ?? PayrollCategory::Office;
        $afterCategory = $this->proposedCategory($existing, $attributes);
        // Use category-aware resolution so Office contracts with a stale daily
        // column default are not treated as a Daily → Monthly transition.
        $beforeStructure = $beforeCategory === PayrollCategory::Office
            ? ContractSalaryStructure::Monthly
            : $existing->resolvedSalaryStructure();
        $afterStructure = $this->proposedResolvedStructure($existing, $attributes, $afterCategory);

        $categoryChanged = $beforeCategory !== $afterCategory;
        $structureChanged = $beforeStructure !== $afterStructure;

        if (! $categoryChanged && ! $structureChanged) {
            return;
        }

        $errorField = $categoryChanged ? 'payroll_category' : 'salary_structure';

        if ($this->hasPayrollHistory($existing)) {
            throw ValidationException::withMessages([
                $errorField => self::PAYROLL_HISTORY_MESSAGE,
            ]);
        }

        if ($this->hasSalaryRevisionHistory($existing)) {
            throw ValidationException::withMessages([
                $errorField => self::REVISION_HISTORY_MESSAGE,
            ]);
        }

        // No payroll or revision history: only an initial setup correction in the
        // contract's original effective month is safe. Later-month transitions
        // must use a replacement contract so earlier months keep their classification.
        if ($existing->start_date === null) {
            throw ValidationException::withMessages([
                $errorField => self::AMBIGUOUS_EFFECTIVE_MONTH_MESSAGE,
            ]);
        }

        $startMonth = SalaryRevisionEffectiveMonth::normalize($existing->start_date->toDateString());
        $intendedMonth = SalaryRevisionEffectiveMonth::tryNormalize($intendedEffectiveFrom);

        if ($intendedMonth === null) {
            throw ValidationException::withMessages([
                $errorField => self::AMBIGUOUS_EFFECTIVE_MONTH_MESSAGE,
            ]);
        }

        if ($intendedMonth !== $startMonth) {
            throw ValidationException::withMessages([
                $errorField => self::LATER_MONTH_MESSAGE,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function proposedCategory(EmployeeContract $existing, array $attributes): PayrollCategory
    {
        if (! array_key_exists('payroll_category', $attributes)) {
            return $existing->payroll_category ?? PayrollCategory::Office;
        }

        $rawCategory = $attributes['payroll_category'];

        if ($rawCategory instanceof PayrollCategory) {
            return $rawCategory;
        }

        return PayrollCategory::tryFrom((string) ($rawCategory ?? '')) ?? PayrollCategory::Office;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function proposedResolvedStructure(
        EmployeeContract $existing,
        array $attributes,
        PayrollCategory $category,
    ): ContractSalaryStructure {
        if ($category === PayrollCategory::Office) {
            return ContractSalaryStructure::Monthly;
        }

        if (! array_key_exists('salary_structure', $attributes)) {
            return $existing->resolvedSalaryStructure();
        }

        $rawStructure = $attributes['salary_structure'];

        if ($rawStructure instanceof ContractSalaryStructure) {
            return $rawStructure;
        }

        if ($rawStructure === null || $rawStructure === '') {
            return ContractSalaryStructure::defaultFor($category);
        }

        return ContractSalaryStructure::from((string) $rawStructure);
    }

    private function hasPayrollHistory(EmployeeContract $contract): bool
    {
        $hasRecords = PayrollRecord::withTrashed()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->exists();

        if ($hasRecords) {
            return true;
        }

        return PayrollWorkAllocation::query()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->exists();
    }

    private function hasSalaryRevisionHistory(EmployeeContract $contract): bool
    {
        return ContractSalaryRevision::withTrashed()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->exists();
    }
}
