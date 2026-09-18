<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\PayrollEmployeeQuery;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;

/**
 * Stable active-employee population serialized during Apply before
 * period-relevant crew source is discovered under locks.
 */
final class CrewPayrollSourceEmployeeBoundary
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * @return list<int>
     */
    public function stableEmployeeIds(
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

        $fromCurrentCrewPayroll = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->pluck('employees.id')
            ->map(intval(...))
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

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

        $merged = collect($fromLines->all())
            ->merge($fromContracts)
            ->merge($fromCurrentCrewPayroll->all())
            ->merge($fromAssignmentHistory->all())
            ->merge($fromHistoricalCrewContracts->all())
            ->unique()
            ->filter(fn (int $employeeId): bool => $employeeId > 0)
            ->sort()
            ->values()
            ->all();

        if ($merged === []) {
            return Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('id')
                ->pluck('id')
                ->map(intval(...))
                ->all();
        }

        return $this->activeEmployeeIds($companyId, $merged);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    private function activeEmployeeIds(int $companyId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', $employeeIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }
}
