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

        /** @var Collection<int, EmployeeContract> $contracts */
        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->when($with !== [], fn ($query) => $query->with($with))
            ->get();

        return $this->resolveManyFromCollection($period, $employeeIds, $contracts);
    }

    /**
     * Resolves applicable contracts from an already-loaded (and optionally locked)
     * crew contract collection using the same overlap and fallback rules as
     * {@see resolveMany()}.
     *
     * @param  list<int>  $employeeIds
     * @param  Collection<int, EmployeeContract>  $contracts
     * @return Collection<int, EmployeeContract|null>
     */
    public function resolveManyFromCollection(
        PayrollPeriod $period,
        array $employeeIds,
        Collection $contracts,
    ): Collection {
        $employeeIds = array_values(array_unique(array_map(intval(...), $employeeIds)));

        if ($employeeIds === []) {
            return collect();
        }

        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        /** @var Collection<int, Collection<int, EmployeeContract>> $byEmployee */
        $byEmployee = $contracts->groupBy(fn (EmployeeContract $contract): int => (int) $contract->employee_id);

        return collect($employeeIds)->mapWithKeys(function (int $employeeId) use ($byEmployee, $periodStart, $periodEnd): array {
            $forEmployee = $byEmployee->get($employeeId, collect());

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
     * Employee IDs whose crew contract would resolve for the payroll period using
     * the same overlap and legacy fallback rules as {@see resolveMany()}. Used to
     * establish Apply lock boundaries when no issue phases exist yet.
     *
     * @return list<int>
     */
    public function crewEmployeeIdsResolvableForPeriod(PayrollPeriod $period): array
    {
        $companyId = (int) $period->company_id;

        /** @var Collection<int, EmployeeContract> $contracts */
        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('payroll_category', PayrollCategory::Crew)
            ->get();

        $employeeIds = $contracts
            ->pluck('employee_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();

        if ($employeeIds === []) {
            return [];
        }

        return $this->resolveManyFromCollection($period, $employeeIds, $contracts)
            ->filter(fn (?EmployeeContract $contract): bool => $contract !== null)
            ->keys()
            ->map(intval(...))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Employee IDs with crew contracts overlapping the payroll period.
     *
     * @return list<int>
     */
    public function crewEmployeeIdsOverlappingPeriod(PayrollPeriod $period): array
    {
        $companyId = (int) $period->company_id;
        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        return EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('payroll_category', PayrollCategory::Crew)
            ->get(['id', 'employee_id', 'start_date', 'end_date'])
            ->filter(fn (EmployeeContract $contract): bool => $this->overlapsPeriod($contract, $periodStart, $periodEnd))
            ->pluck('employee_id')
            ->map(intval(...))
            ->unique()
            ->sort()
            ->values()
            ->all();
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

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public function ambiguousEmployeeIds(PayrollPeriod $period, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map(intval(...), $employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        $periodStart = $period->start_date?->toDateString();
        $periodEnd = $period->end_date?->toDateString();

        return EmployeeContract::query()
            ->where('company_id', (int) $period->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->get()
            ->filter(fn (EmployeeContract $contract): bool => $this->overlapsPeriod($contract, $periodStart, $periodEnd))
            ->groupBy(fn (EmployeeContract $contract): int => (int) $contract->employee_id)
            ->filter(fn (Collection $contracts): bool => $contracts->count() > 1)
            ->keys()
            ->map(intval(...))
            ->values()
            ->all();
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
