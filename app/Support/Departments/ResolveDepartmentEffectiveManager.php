<?php

namespace App\Support\Departments;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class ResolveDepartmentEffectiveManager
{
    /**
     * @param  Collection<int, Department>  $departmentsById
     */
    public static function effectiveManagerIdForDepartment(int $departmentId, Collection $departmentsById): ?int
    {
        $visited = [];
        $currentId = $departmentId;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;

            $department = $departmentsById->get($currentId);

            if ($department === null) {
                return null;
            }

            if ($department->manager_id !== null) {
                return (int) $department->manager_id;
            }

            $currentId = $department->parent_id;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public static function departmentIdsForManager(int $companyId, int $managerEmployeeId): array
    {
        $departmentsById = self::departmentsByIdForCompany($companyId);

        return $departmentsById
            ->filter(
                fn (Department $department): bool => self::effectiveManagerIdForDepartment(
                    $department->id,
                    $departmentsById,
                ) === $managerEmployeeId,
            )
            ->keys()
            ->values()
            ->all();
    }

    public static function managerForEmployee(Employee $employee): ?Employee
    {
        if ($employee->department_id === null) {
            return null;
        }

        $departmentsById = self::departmentsByIdForCompany((int) $employee->company_id);
        $managerId = self::effectiveManagerIdForDepartment((int) $employee->department_id, $departmentsById);

        if ($managerId === null) {
            return null;
        }

        return Employee::query()
            ->where('company_id', $employee->company_id)
            ->whereKey($managerId)
            ->first(['id', 'name', 'employee_no']);
    }

    /**
     * @return array{id: int, employee_no: string|null, name: string|null}|null
     */
    public static function managerPayloadForEmployee(Employee $employee): ?array
    {
        $manager = self::managerForEmployee($employee);

        if ($manager === null) {
            return null;
        }

        return [
            'id' => $manager->id,
            'employee_no' => $manager->employee_no,
            'name' => $manager->name,
        ];
    }

    /**
     * @return Collection<int, Department>
     */
    private static function departmentsByIdForCompany(int $companyId): Collection
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id', 'manager_id'])
            ->keyBy('id');
    }
}
