<?php

namespace App\Support\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly EmployeeDirectoryFilters $filters,
    ) {}

    public function apply(Builder $query): Builder
    {
        self::applyAttributeFilters($query, $this->companyId, $this->filters);

        return $query->latest('id');
    }

    public static function applyAttributeFilters(
        Builder $query,
        int $companyId,
        EmployeeDirectoryFilters $filters,
        bool $exceptDepartment = false,
        bool $exceptPosition = false,
    ): void {
        $query
            ->where('company_id', $companyId)
            ->when($filters->branchId, fn (Builder $q) => $q->where('branch_id', $filters->branchId))
            ->when(! $exceptDepartment && $filters->departmentId, function (Builder $q) use ($companyId, $filters): void {
                $departmentId = (int) $filters->departmentId;

                $departments = Department::query()
                    ->where('company_id', $companyId)
                    ->get(['id', 'parent_id'])
                    ->map(fn (Department $department): array => [
                        'id' => $department->id,
                        'parent_id' => $department->parent_id,
                    ]);

                $departmentIds = DepartmentDescendantIds::includingSelf($departmentId, $departments);

                $q->whereIn('department_id', $departmentIds);
            })
            ->when(! $exceptPosition && $filters->positionId, fn (Builder $q) => $q->where('position_id', $filters->positionId))
            ->when($filters->status, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->managerId, function (Builder $q) use ($companyId, $filters): void {
                $departmentIds = ResolveDepartmentEffectiveManager::departmentIdsForManager(
                    $companyId,
                    (int) $filters->managerId,
                );

                if ($departmentIds === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->whereIn('department_id', $departmentIds);
            })
            ->when($filters->genderId, fn (Builder $q) => $q->where('gender_id', $filters->genderId))
            ->when($filters->nationalityId, fn (Builder $q) => $q->where('nationality_id', $filters->nationalityId))
            ->when($filters->visaTypeId, fn (Builder $q) => $q->where('visa_type_id', $filters->visaTypeId))
            ->when($filters->companyVisaTypeId, fn (Builder $q) => $q->where('company_visa_type_id', $filters->companyVisaTypeId))
            ->when($filters->rankId, fn (Builder $q) => $q->where('rank_id', $filters->rankId))
            ->when($filters->approvalLocationId, function (Builder $q) use ($filters): void {
                $ids = collect(explode(',', $filters->approvalLocationId))
                    ->map(fn (string $id): int => (int) trim($id))
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                if ($ids === []) {
                    return;
                }

                $q->whereHas(
                    'approvalLocations',
                    fn (Builder $rel) => $rel->whereIn('approval_locations.id', $ids),
                );
            })
            ->when($filters->sssaOptionId, function (Builder $q) use ($filters): void {
                $ids = collect(explode(',', $filters->sssaOptionId))
                    ->map(fn (string $id): int => (int) trim($id))
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                if ($ids === []) {
                    return;
                }

                $q->whereHas(
                    'sssaOptions',
                    fn (Builder $rel) => $rel->whereIn('sssa_options.id', $ids),
                );
            })
            ->when($filters->search, function (Builder $q) use ($filters): void {
                $search = $filters->search;

                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('work_email', 'like', "%{$search}%")
                        ->orWhere('personal_email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when(
                $filters->crewStatus !== '' && EmployeeCrewStatusFilter::isValid($filters->crewStatus),
                function (Builder $q) use ($companyId, $filters): void {
                    $matchingIds = EmployeeCrewStatusFilter::matchingEmployeeIds($companyId, $filters->crewStatus);

                    if ($matchingIds === []) {
                        $q->whereRaw('1 = 0');

                        return;
                    }

                    $q->whereIn('id', $matchingIds);
                },
            );
    }

    public function base(): Builder
    {
        return $this->apply(Employee::query());
    }
}
