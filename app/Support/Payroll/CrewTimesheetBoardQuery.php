<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CrewTimesheetBoardQuery
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $companyId,
        int $periodId,
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = Employee::query()
            ->where('employees.company_id', $companyId)
            ->whereHas('currentContract', function (Builder $contractQuery) {
                $contractQuery->where('payroll_category', PayrollCategory::Crew);
            })
            ->with([
                'crewTimesheets' => fn ($timesheetQuery) => $timesheetQuery->where('period_id', $periodId),
            ]);

        $this->applySearch($query, $search);

        return $query
            ->orderBy('employees.name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Employee $employee) use ($periodId) {
                /** @var CrewTimesheet|null $timesheet */
                $timesheet = $employee->crewTimesheets->first();

                return CrewTimesheetResource::toBoardRow($employee, $timesheet, $periodId);
            });
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
