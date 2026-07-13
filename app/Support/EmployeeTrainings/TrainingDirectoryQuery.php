<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeTraining;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class TrainingDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly TrainingDirectoryFilters $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query);

        $today = now()->toDateString();

        return $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 WHEN expiry_date < ? THEN 0 ELSE 1 END', [$today])
            ->orderBy('expiry_date')
            ->orderByDesc('employee_trainings.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeTraining $training) => TrainingListResource::toArray($training));
    }

    /**
     * @return Builder<EmployeeTraining>
     */
    public function exportQuery(): Builder
    {
        $query = $this->baseQuery();

        $this->applyFilters($query);

        $today = now()->toDateString();

        return $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 WHEN expiry_date < ? THEN 0 ELSE 1 END', [$today])
            ->orderBy('expiry_date')
            ->orderByDesc('employee_trainings.id');
    }

    /**
     * @return Builder<EmployeeTraining>
     */
    private function baseQuery(): Builder
    {
        return EmployeeTraining::query()
            ->where('employee_trainings.company_id', $this->companyId)
            ->with([
                'course:id,name',
                'country:id,name',
                'employee:id,name,employee_no,image,company_id,branch_id,department_id,position_id',
                'employee.department:id,name',
                'employee.position:id,title',
            ]);
    }

    /**
     * @param  Builder<EmployeeTraining>  $query
     */
    private function applyFilters(Builder $query): void
    {
        if ($this->filters->expiry !== 'all') {
            TrainingExpiry::applyExpiryFilter($query, $this->filters->expiry);
        }

        $query
            ->when($this->filters->issueDate !== '', function (Builder $inner): void {
                $inner->whereDate('employee_trainings.issue_date', $this->filters->issueDate);
            })
            ->when($this->filters->courseId !== '', function (Builder $inner): void {
                $inner->where('employee_trainings.course_id', (int) $this->filters->courseId);
            })
            ->when($this->filters->institute !== '', function (Builder $inner): void {
                $inner->where('employee_trainings.institute_center', 'like', '%'.$this->filters->institute.'%');
            })
            ->when($this->filters->countryId !== '', function (Builder $inner): void {
                $inner->where('employee_trainings.country_id', (int) $this->filters->countryId);
            })
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $search = $this->filters->search;
                $like = '%'.$search.'%';

                $inner->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('employee_trainings.institute_center', 'like', $like)
                        ->orWhereHas('course', function (Builder $courseQuery) use ($like): void {
                            $courseQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($like): void {
                            $employeeQuery
                                ->where('name', 'like', $like)
                                ->orWhere('employee_no', 'like', $like);
                        });
                });
            })
            ->whereHas('employee', function (Builder $employeeQuery): void {
                $directoryFilters = new EmployeeDirectoryFilters(
                    branchId: $this->filters->branchId,
                    departmentId: $this->filters->departmentId,
                );

                EmployeeDirectoryQuery::applyAttributeFilters(
                    $employeeQuery,
                    $this->companyId,
                    $directoryFilters,
                    exceptDepartment: false,
                    exceptPosition: true,
                );
            });
    }
}
