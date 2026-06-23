<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PayrollPeriodBoardQuery
{
    public function __construct(
        private readonly CountWorkingDaysInRange $countWorkingDays,
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
                'crewTimesheets' => fn ($timesheetQuery) => $timesheetQuery->where('period_id', $period->id),
            ]);
        }

        $this->applySearch($query, $search);

        $attendanceByEmployee = $payrollCategory === PayrollCategory::Office
            ? $this->loadOfficeAttendanceByEmployee($companyId, $period)
            : Collection::make();

        $companyWorkingDays = $this->resolveCompanyWorkingDays($companyId);
        $workingDaysInPeriod = $this->countWorkingDays->count(
            $period->start_date,
            $period->end_date,
            $companyWorkingDays,
        );

        return $query
            ->orderBy('employees.name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Employee $employee) use ($period, $payrollCategory, $attendanceByEmployee, $companyWorkingDays, $workingDaysInPeriod) {
                if ($payrollCategory === PayrollCategory::Crew) {
                    /** @var CrewTimesheet|null $timesheet */
                    $timesheet = $employee->crewTimesheets->first();

                    return CrewTimesheetResource::toBoardRow($employee, $timesheet, $period->id);
                }

                /** @var Collection<int, AttendanceRecord> $records */
                $records = $attendanceByEmployee->get($employee->id, Collection::make());

                $summary = $records->isEmpty()
                    ? null
                    : OfficeAttendanceSummary::fromRecords($records, $workingDaysInPeriod, $companyWorkingDays);

                return OfficePayrollBoardRow::toArray($employee, $period->id, $summary);
            });
    }

    /**
     * @return Collection<int, Collection<int, AttendanceRecord>>
     */
    private function loadOfficeAttendanceByEmployee(int $companyId, PayrollPeriod $period): Collection
    {
        $employeeIds = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Office)
            ->pluck('employees.id');

        if ($employeeIds->isEmpty()) {
            return Collection::make();
        }

        return AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('date', '>=', $period->start_date)
            ->whereDate('date', '<=', $period->end_date)
            ->orderBy('date')
            ->get()
            ->groupBy('employee_id');
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
     * @return list<int>
     */
    private function resolveCompanyWorkingDays(int $companyId): array
    {
        $workingDays = Company::query()
            ->whereKey($companyId)
            ->value('working_days');

        if (! is_array($workingDays) || $workingDays === []) {
            return [1, 2, 3, 4, 5];
        }

        return array_map(intval(...), $workingDays);
    }
}
