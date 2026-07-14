<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class PayrollPeriodBoardQuery
{
    public function __construct(
        private readonly OfficeLeavePeriodSummary $leavePeriodSummary,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $companyId,
        PayrollPeriod $period,
        ?string $search = null,
        int $perPage = 25,
        ?PayrollPeriodBoardFilters $filters = null,
    ): LengthAwarePaginator {
        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;
        $filters ??= new PayrollPeriodBoardFilters;

        $query = PayrollEmployeeQuery::activeQuery($companyId, $payrollCategory);

        $query->with([
            'department.parent:id,name',
            'position:id,title',
        ]);

        if ($payrollCategory === PayrollCategory::Crew) {
            $query->with([
                'primaryBankAccount.bank:id,name',
                'currentContract' => fn ($q) => $q->select([
                    'employee_contracts.id',
                    'employee_contracts.employee_id',
                    'employee_contracts.payroll_category',
                    'employee_contracts.salary_structure',
                    'employee_contracts.basic_salary',
                    'employee_contracts.housing_allowance',
                    'employee_contracts.transport_allowance',
                    'employee_contracts.other_allowances',
                    'employee_contracts.supplementary_allowance',
                    'employee_contracts.site_allowance',
                ])->with([
                    'salaryRevisions' => fn ($revisions) => $revisions
                        ->with('lines')
                        ->orderByDesc('effective_from')
                        ->orderByDesc('version'),
                    'salaryComponents',
                ]),
                'crewTimesheets' => fn ($timesheetQuery) => $timesheetQuery->where('period_id', $period->id),
            ]);
        }

        if ($payrollCategory === PayrollCategory::Office) {
            $query->with([
                'primaryBankAccount.bank:id,name',
                'currentContract' => fn ($q) => $q->select([
                    'employee_contracts.id',
                    'employee_contracts.employee_id',
                    'employee_contracts.payroll_category',
                    'employee_contracts.salary_structure',
                    'employee_contracts.basic_salary',
                    'employee_contracts.housing_allowance',
                    'employee_contracts.transport_allowance',
                    'employee_contracts.other_allowances',
                ])->with([
                    'salaryRevisions' => fn ($revisions) => $revisions
                        ->with('lines')
                        ->orderByDesc('effective_from')
                        ->orderByDesc('version'),
                    'salaryComponents',
                ]),
            ]);
        }

        PayrollPeriodBoardEmployeeScope::apply($query, $companyId, $period, $search, $filters);

        $leaveByEmployee = $payrollCategory === PayrollCategory::Office
            ? $this->loadOfficeLeaveByEmployee($companyId, $period, $filters)
            : Collection::make();

        return $query
            ->orderBy('employees.name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Employee $employee) use ($period, $payrollCategory, $leaveByEmployee, $companyId) {
                if ($payrollCategory === PayrollCategory::Crew) {
                    /** @var CrewTimesheet|null $timesheet */
                    $timesheet = $employee->crewTimesheets->first();

                    return CrewTimesheetResource::toBoardRow(
                        $employee,
                        $timesheet,
                        $period->id,
                        $period->start_date,
                    );
                }

                $summary = $leaveByEmployee->get(
                    $employee->id,
                    $this->leavePeriodSummary->empty($companyId),
                );

                return OfficePayrollBoardRow::toArray(
                    $employee,
                    $period->id,
                    $summary,
                    $period->start_date,
                );
            });
    }

    /**
     * @return list<int>
     */
    public function allEmployeeIds(
        int $companyId,
        PayrollPeriod $period,
        ?string $search = null,
        ?PayrollPeriodBoardFilters $filters = null,
    ): array {
        $filters ??= new PayrollPeriodBoardFilters;

        $query = PayrollEmployeeQuery::activeQuery(
            $companyId,
            $period->payroll_category ?? PayrollCategory::Crew,
        );

        PayrollPeriodBoardEmployeeScope::apply($query, $companyId, $period, $search, $filters);

        return $query
            ->orderBy('employees.name')
            ->pluck('employees.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, EmployeeLeavePeriodSummary>
     */
    private function loadOfficeLeaveByEmployee(
        int $companyId,
        PayrollPeriod $period,
        PayrollPeriodBoardFilters $filters,
    ): Collection {
        $employeeIdsQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Office);

        PayrollPeriodBoardEmployeeScope::apply(
            $employeeIdsQuery,
            $companyId,
            $period,
            null,
            $filters,
        );

        $employeeIds = $employeeIdsQuery
            ->pluck('employees.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->leavePeriodSummary->forEmployees(
            $companyId,
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
            $employeeIds,
        );
    }
}
