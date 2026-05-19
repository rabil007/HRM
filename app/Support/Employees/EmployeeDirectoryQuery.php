<?php

namespace App\Support\Employees;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly EmployeeDirectoryFilters $filters,
    ) {}

    public function apply(Builder $query): Builder
    {
        return $query
            ->where('company_id', $this->companyId)
            ->when($this->filters->branchId, fn (Builder $q) => $q->where('branch_id', $this->filters->branchId))
            ->when($this->filters->departmentId, function (Builder $q): void {
                $departmentId = (int) $this->filters->departmentId;

                $departments = Department::query()
                    ->where('company_id', $this->companyId)
                    ->get(['id', 'parent_id'])
                    ->map(fn (Department $department): array => [
                        'id' => $department->id,
                        'parent_id' => $department->parent_id,
                    ]);

                $departmentIds = DepartmentDescendantIds::includingSelf($departmentId, $departments);

                $q->whereIn('department_id', $departmentIds);
            })
            ->when($this->filters->positionId, fn (Builder $q) => $q->where('position_id', $this->filters->positionId))
            ->when($this->filters->status, fn (Builder $q) => $q->where('status', $this->filters->status))
            ->when($this->filters->search, function (Builder $q): void {
                $search = $this->filters->search;

                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('work_email', 'like', "%{$search}%")
                        ->orWhere('personal_email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('id');
    }

    public function base(): Builder
    {
        return $this->apply(Employee::query());
    }
}
