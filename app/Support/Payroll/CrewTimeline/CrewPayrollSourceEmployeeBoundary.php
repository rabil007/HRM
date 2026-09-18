<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;

/**
 * Stable Employee parent locks for final Crew Timesheet Apply.
 *
 * Lock boundary is intentionally broader than the source hash: every active
 * company employee can start new Crew source, while existing preparation
 * source employees remain protected even when inactive.
 */
final class CrewPayrollSourceEmployeeBoundary
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * Employees already represented by current preparation or historical Crew
     * source, regardless of current employee status.
     *
     * @return list<int>
     */
    public function existingSourceEmployeeIds(
        int $companyId,
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
    ): array {
        $fromLines = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->pluck('employee_id')
            ->map(intval(...))
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

        $fromContracts = $this->resolveContract->crewEmployeeIdsResolvableForPeriod($period);

        $fromAssignmentHistory = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('employee_id')
            ->map(intval(...))
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

        $fromHistoricalCrewContracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('payroll_category', PayrollCategory::Crew)
            ->distinct()
            ->pluck('employee_id')
            ->map(intval(...))
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

        return collect($fromLines->all())
            ->merge($fromContracts)
            ->merge($fromAssignmentHistory->all())
            ->merge($fromHistoricalCrewContracts->all())
            ->unique()
            ->filter(fn (int $employeeId): bool => $employeeId > 0)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Active employees who can create brand-new Crew source during Apply.
     *
     * @return list<int>
     */
    public function potentialNewSourceEmployeeIds(int $companyId): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Deterministic Employee FOR UPDATE population for final Apply.
     *
     * @return list<int>
     */
    public function stableEmployeeIds(
        int $companyId,
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
    ): array {
        return collect([
            ...$this->existingSourceEmployeeIds($companyId, $period, $preparation),
            ...$this->potentialNewSourceEmployeeIds($companyId),
        ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
