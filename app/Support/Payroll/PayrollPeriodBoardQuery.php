<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
                    'employee_contracts.basic_salary',
                    'employee_contracts.supplementary_allowance',
                    'employee_contracts.site_allowance',
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
                    'employee_contracts.basic_salary',
                    'employee_contracts.housing_allowance',
                    'employee_contracts.transport_allowance',
                    'employee_contracts.other_allowances',
                ]),
            ]);
        }

        $this->applySearch($query, $search);
        $this->applyBoardFilters($query, $companyId, $filters);

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

                    return CrewTimesheetResource::toBoardRow($employee, $timesheet, $period->id);
                }

                $summary = $leaveByEmployee->get(
                    $employee->id,
                    $this->leavePeriodSummary->empty($companyId),
                );

                return OfficePayrollBoardRow::toArray($employee, $period->id, $summary);
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
        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;
        $filters ??= new PayrollPeriodBoardFilters;

        $query = PayrollEmployeeQuery::activeQuery($companyId, $payrollCategory);

        $this->applySearch($query, $search);
        $this->applyBoardFilters($query, $companyId, $filters);

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
        $this->applyBoardFilters($employeeIdsQuery, $companyId, $filters);

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

    /**
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $term = '%'.mb_strtolower(trim($search)).'%';

        $query->where(function (Builder $builder) use ($term) {
            $builder
                ->whereRaw('LOWER(employees.employee_no) LIKE ?', [$term])
                ->orWhereRaw('LOWER(employees.name) LIKE ?', [$term]);
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applyBoardFilters(
        Builder $query,
        int $companyId,
        PayrollPeriodBoardFilters $filters,
    ): void {
        if (! $filters->isActive()) {
            return;
        }

        EmployeeDirectoryQuery::applyAttributeFilters(
            $query,
            $companyId,
            new EmployeeDirectoryFilters(
                departmentId: $filters->departmentId,
                positionId: $filters->positionId,
            ),
            exceptDepartment: false,
            exceptPosition: false,
        );
    }
}
