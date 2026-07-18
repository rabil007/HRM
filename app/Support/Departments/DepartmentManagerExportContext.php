<?php

namespace App\Support\Departments;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class DepartmentManagerExportContext
{
    /**
     * @param  Collection<int, Department>  $departmentsById
     * @param  array<int, int|null>  $managerIdByDepartmentId
     * @param  Collection<int, Employee>  $managersById
     */
    private function __construct(
        private readonly Collection $departmentsById,
        private readonly array $managerIdByDepartmentId,
        private readonly Collection $managersById,
    ) {}

    public static function forCompany(int $companyId): self
    {
        $departmentsById = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id', 'manager_id'])
            ->keyBy('id');

        $managerIdByDepartmentId = $departmentsById
            ->mapWithKeys(fn (Department $department): array => [
                $department->id => ResolveDepartmentEffectiveManager::effectiveManagerIdForDepartment(
                    $department->id,
                    $departmentsById,
                ),
            ])
            ->all();

        $managersById = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_values(array_filter($managerIdByDepartmentId)))
            ->get(['id', 'name'])
            ->keyBy('id');

        return new self($departmentsById, $managerIdByDepartmentId, $managersById);
    }

    public function managerNameForEmployee(Employee $employee): ?string
    {
        if ($employee->department_id === null) {
            return null;
        }

        $managerId = $this->managerIdByDepartmentId[(int) $employee->department_id] ?? null;

        return $managerId === null ? null : $this->managersById->get($managerId)?->name;
    }
}
