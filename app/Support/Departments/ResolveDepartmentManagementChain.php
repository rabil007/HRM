<?php

namespace App\Support\Departments;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * @phpstan-type ManagementChainEntry array{
 *     manager: Employee,
 *     manager_user: User|null,
 *     source_department: Department,
 *     is_direct: bool,
 *     hierarchy_order: int
 * }
 */
final class ResolveDepartmentManagementChain
{
    /**
     * @return list<ManagementChainEntry>
     */
    public static function forEmployee(Employee $employee): array
    {
        if ($employee->department_id === null) {
            return [];
        }

        return self::forDepartment(
            (int) $employee->company_id,
            (int) $employee->department_id,
        );
    }

    /**
     * @return list<ManagementChainEntry>
     */
    public static function forDepartment(int $companyId, int $departmentId): array
    {
        $departmentsById = self::departmentsByIdForCompany($companyId);

        if (! $departmentsById->has($departmentId)) {
            return [];
        }

        $chain = [];
        $seenManagerIds = [];
        $visitedDepartmentIds = [];
        $currentId = $departmentId;
        $order = 1;
        $isStartingDepartment = true;

        while ($currentId !== null && ! isset($visitedDepartmentIds[$currentId])) {
            $visitedDepartmentIds[$currentId] = true;
            $department = $departmentsById->get($currentId);

            if ($department === null) {
                break;
            }

            if ($department->manager_id !== null) {
                $managerId = (int) $department->manager_id;

                if (! isset($seenManagerIds[$managerId])) {
                    $seenManagerIds[$managerId] = true;

                    $manager = $department->relationLoaded('manager')
                        ? $department->manager
                        : null;

                    if ($manager === null || (int) $manager->id !== $managerId) {
                        $manager = Employee::query()
                            ->where('company_id', $companyId)
                            ->whereKey($managerId)
                            ->with('user:id,name,email,status')
                            ->first();
                    }

                    if ($manager !== null && (int) $manager->company_id === $companyId) {
                        $chain[] = [
                            'manager' => $manager,
                            'manager_user' => $manager->user,
                            'source_department' => $department,
                            'is_direct' => $isStartingDepartment && $department->manager_id !== null,
                            'hierarchy_order' => $order,
                        ];
                        $order++;
                    }
                }
            }

            $isStartingDepartment = false;
            $currentId = $department->parent_id !== null ? (int) $department->parent_id : null;
        }

        return $chain;
    }

    /**
     * Effective manager for a department, with direct vs inherited metadata.
     *
     * @return array{
     *     manager: Employee|null,
     *     source_department: Department|null,
     *     is_direct: bool,
     *     is_inherited: bool
     * }|null
     */
    public static function effectiveManagerDetail(int $companyId, int $departmentId): ?array
    {
        $departmentsById = self::departmentsByIdForCompany($companyId);
        $department = $departmentsById->get($departmentId);

        if ($department === null) {
            return null;
        }

        if ($department->manager_id !== null) {
            $manager = $department->manager;

            if ($manager === null) {
                $manager = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $department->manager_id)
                    ->first(['id', 'company_id', 'name', 'employee_no', 'user_id', 'status']);
            }

            return [
                'manager' => $manager,
                'source_department' => $department,
                'is_direct' => true,
                'is_inherited' => false,
            ];
        }

        $visited = [];
        $currentId = $department->parent_id !== null ? (int) $department->parent_id : null;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $ancestor = $departmentsById->get($currentId);

            if ($ancestor === null) {
                break;
            }

            if ($ancestor->manager_id !== null) {
                $manager = $ancestor->manager;

                if ($manager === null) {
                    $manager = Employee::query()
                        ->where('company_id', $companyId)
                        ->whereKey((int) $ancestor->manager_id)
                        ->first(['id', 'company_id', 'name', 'employee_no', 'user_id', 'status']);
                }

                return [
                    'manager' => $manager,
                    'source_department' => $ancestor,
                    'is_direct' => false,
                    'is_inherited' => true,
                ];
            }

            $currentId = $ancestor->parent_id !== null ? (int) $ancestor->parent_id : null;
        }

        return [
            'manager' => null,
            'source_department' => null,
            'is_direct' => false,
            'is_inherited' => false,
        ];
    }

    /**
     * @return Collection<int, Department>
     */
    private static function departmentsByIdForCompany(int $companyId): Collection
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->with(['manager:id,company_id,name,employee_no,user_id,status', 'manager.user:id,name,email,status'])
            ->get(['id', 'company_id', 'parent_id', 'manager_id', 'name', 'code', 'status'])
            ->keyBy('id');
    }
}
