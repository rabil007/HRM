<?php

namespace App\Support\Contracts;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

final class AssertContractSalaryStructureChangeAllowed
{
    public const PAYROLL_HISTORY_MESSAGE = 'This contract already has payroll history. Its salary structure cannot be changed directly. Create a new effective-dated contract to preserve historical payroll calculations.';

    public const REVISION_HISTORY_MESSAGE = 'This contract already has salary revision history. Its salary structure cannot be changed directly. Create a new effective-dated contract to preserve historical salary calculations.';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assert(EmployeeContract $existing, array $attributes): void
    {
        $before = $existing->resolvedSalaryStructure();
        $after = $this->proposedResolvedStructure($existing, $attributes);

        if ($before === $after) {
            return;
        }

        if ($this->hasPayrollHistory($existing)) {
            throw ValidationException::withMessages([
                'salary_structure' => self::PAYROLL_HISTORY_MESSAGE,
            ]);
        }

        if ($this->hasSalaryRevisionHistory($existing)) {
            throw ValidationException::withMessages([
                'salary_structure' => self::REVISION_HISTORY_MESSAGE,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function proposedResolvedStructure(EmployeeContract $existing, array $attributes): ContractSalaryStructure
    {
        $rawCategory = $attributes['payroll_category'] ?? $existing->payroll_category;
        $category = $rawCategory instanceof PayrollCategory
            ? $rawCategory
            : (PayrollCategory::tryFrom((string) ($rawCategory ?? '')) ?? PayrollCategory::Office);

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
