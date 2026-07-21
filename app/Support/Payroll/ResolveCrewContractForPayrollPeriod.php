<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

/**
 * Resolves the crew employment contract applicable to a specific payroll period.
 *
 * A present-day "current" contract must never be used for a historical payroll
 * period. The applicable contract must belong to the active company and
 * employee, use the Crew payroll category, not be soft deleted, and overlap the
 * payroll period. When no crew contract overlaps the period, the latest active
 * crew contract is returned as a deterministic fallback so legacy data keeps
 * generating.
 */
final class ResolveCrewContractForPayrollPeriod
{
    /**
     * @param  list<string>  $with
     */
    public function resolve(Employee|int $employee, PayrollPeriod $period, array $with = []): ?EmployeeContract
    {
        $employeeId = $employee instanceof Employee ? (int) $employee->id : (int) $employee;

        return $this->resolveMany($period, [$employeeId], $with)->get($employeeId);
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $with
     * @return Collection<int, EmployeeContract|null>
     */
    public function resolveMany(PayrollPeriod $period, array $employeeIds, array $with = []): Collection
    {
        $employeeIds = array_values(array_unique(array_map(intval(...), $employeeIds)));

        if ($employeeIds === []) {
            return collect();
        }

        $companyId = (int) $period->company_id;
        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        /** @var Collection<int, EmployeeContract> $contracts */
        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->when($with !== [], fn ($query) => $query->with($with))
            ->get();

        return collect($employeeIds)->mapWithKeys(function (int $employeeId) use ($contracts, $periodStart, $periodEnd): array {
            $forEmployee = $contracts->where('employee_id', $employeeId);

            $overlapping = $forEmployee
                ->filter(fn (EmployeeContract $contract): bool => $this->overlapsPeriod($contract, $periodStart, $periodEnd))
                ->sort($this->deterministicOrder())
                ->values();

            $contract = $overlapping->first()
                ?? $forEmployee
                    ->where('status', 'active')
                    ->sortByDesc('id')
                    ->first();

            return [$employeeId => $contract];
        });
    }

    /**
     * Whether more than one crew contract overlaps the period, which signals
     * ambiguous historical data that should be reported to operators.
     */
    public function hasAmbiguousOverlap(Employee|int $employee, PayrollPeriod $period): bool
    {
        $employeeId = $employee instanceof Employee ? (int) $employee->id : (int) $employee;
        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        return EmployeeContract::query()
            ->where('company_id', (int) $period->company_id)
            ->where('employee_id', $employeeId)
            ->where('payroll_category', PayrollCategory::Crew)
            ->get()
            ->filter(fn (EmployeeContract $contract): bool => $this->overlapsPeriod($contract, $periodStart, $periodEnd))
            ->count() > 1;
    }

    public function resolveSalaryStructure(Employee|int $employee, PayrollPeriod $period): ContractSalaryStructure
    {
        return $this->resolve($employee, $period)?->resolvedSalaryStructure()
            ?? ContractSalaryStructure::Daily;
    }

    private function overlapsPeriod(EmployeeContract $contract, ?string $periodStart, ?string $periodEnd): bool
    {
        $start = $contract->start_date?->toDateString();
        $end = $contract->end_date?->toDateString();

        if ($start !== null && $periodEnd !== null && $start > $periodEnd) {
            return false;
        }

        if ($end !== null && $periodStart !== null && $end < $periodStart) {
            return false;
        }

        return true;
    }

    private function deterministicOrder(): callable
    {
        return function (EmployeeContract $left, EmployeeContract $right): int {
            $leftStart = $right->start_date?->toDateString() ?? '';
            $rightStart = $left->start_date?->toDateString() ?? '';

            return [$leftStart, (int) $right->id] <=> [$rightStart, (int) $left->id];
        };
    }
}
