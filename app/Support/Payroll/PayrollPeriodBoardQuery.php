<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
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
    ): LengthAwarePaginator {
        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;

        $query = PayrollEmployeeQuery::activeQuery($companyId, $payrollCategory);

        if ($payrollCategory === PayrollCategory::Crew) {
            $query->with([
                'primaryBankAccount.bank:id,name',
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

        $leaveByEmployee = $payrollCategory === PayrollCategory::Office
            ? $this->loadOfficeLeaveByEmployee($companyId, $period)
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
     * @return Collection<int, EmployeeLeavePeriodSummary>
     */
    private function loadOfficeLeaveByEmployee(int $companyId, PayrollPeriod $period): Collection
    {
        $employeeIds = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Office)
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
}
