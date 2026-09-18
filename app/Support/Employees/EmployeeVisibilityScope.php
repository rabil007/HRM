<?php

namespace App\Support\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\UnrestrictedCompanyAccess;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeVisibilityScope
{
    /**
     * @var array<string, list<int>|null>
     */
    private static array $resolvedAllowedDepartmentIds = [];

    /**
     * Clear memoized department IDs (useful for tests and user mutations).
     */
    public static function clearCache(): void
    {
        self::$resolvedAllowedDepartmentIds = [];
    }

    /**
     * Apply employee visibility scope to an Employee query.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function apply(Builder $query, ?User $user, int $companyId): Builder
    {
        if ($user === null || $companyId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('employees.company_id', $companyId);

        $allowedIds = self::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return $query;
        }

        if ($allowedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employees.department_id', $allowedIds);
    }

    /**
     * Determine whether a user may access a particular Employee.
     */
    public static function canAccess(?User $user, Employee $employee, int $companyId, bool $allowSelf = false): bool
    {
        if ($user === null || $companyId <= 0) {
            return false;
        }

        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ($allowSelf && $employee->user_id !== null && (int) $employee->user_id === (int) $user->id) {
            return true;
        }

        $allowedIds = self::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return true;
        }

        if ($allowedIds === [] || $employee->department_id === null) {
            return false;
        }

        return in_array((int) $employee->department_id, $allowedIds, true);
    }

    /**
     * Resolve allowed department IDs for a user in a given company.
     *
     * Returns:
     * - null: all departments are allowed (no restriction)
     * - list<int>: only these department IDs (and their descendants) are allowed
     * - empty list []: access is restricted but resolves to no departments (fail closed)
     *
     * @return list<int>|null
     */
    public static function allowedDepartmentIds(?User $user, int $companyId): ?array
    {
        if ($user === null || $companyId <= 0) {
            return [];
        }

        if (UnrestrictedCompanyAccess::grants($user)) {
            return null;
        }

        $cacheKey = $user->getKey().':'.$companyId;
        if (array_key_exists($cacheKey, self::$resolvedAllowedDepartmentIds)) {
            return self::$resolvedAllowedDepartmentIds[$cacheKey];
        }

        $roles = Role::query()
            ->where('spatie_roles.company_id', $companyId)
            ->join('spatie_model_has_roles', 'spatie_model_has_roles.role_id', '=', 'spatie_roles.id')
            ->where('spatie_model_has_roles.model_type', $user->getMorphClass())
            ->where('spatie_model_has_roles.model_id', $user->getKey())
            ->where('spatie_model_has_roles.company_id', $companyId)
            ->select('spatie_roles.*')
            ->with('employeeVisibilityDepartments')
            ->get();

        if ($roles->isEmpty()) {
            return self::$resolvedAllowedDepartmentIds[$cacheKey] = [];
        }

        // Owner role is protected and always all
        if ($roles->contains(fn (Role $r) => $r->name === 'Owner')) {
            return self::$resolvedAllowedDepartmentIds[$cacheKey] = null;
        }

        // If any role has SCOPE_ALL, user has unrestricted employee access
        if ($roles->contains(fn (Role $r) => $r->employee_visibility_scope === Role::SCOPE_ALL)) {
            return self::$resolvedAllowedDepartmentIds[$cacheKey] = null;
        }

        // Gather configured department IDs from roles with SCOPE_SELECTED_DEPARTMENTS
        $configuredDepartmentIds = [];

        foreach ($roles as $role) {
            if ($role->employee_visibility_scope === Role::SCOPE_SELECTED_DEPARTMENTS) {
                foreach ($role->employeeVisibilityDepartments as $dept) {
                    if ((int) $dept->company_id === $companyId && $dept->status === 'active') {
                        $configuredDepartmentIds[] = (int) $dept->id;
                    }
                }
            }
        }

        $configuredDepartmentIds = array_values(array_unique($configuredDepartmentIds));

        if ($configuredDepartmentIds === []) {
            return self::$resolvedAllowedDepartmentIds[$cacheKey] = [];
        }

        $expanded = self::expandDepartmentsWithDescendants($companyId, $configuredDepartmentIds);

        return self::$resolvedAllowedDepartmentIds[$cacheKey] = $expanded;
    }

    /**
     * Expand configured departments to include all active descendant departments.
     *
     * @param  list<int>  $departmentIds
     * @return list<int>
     */
    public static function expandDepartmentsWithDescendants(int $companyId, array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'parent_id'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'parent_id' => $department->parent_id !== null ? (int) $department->parent_id : null,
            ])
            ->all();

        $expanded = [];

        foreach ($departmentIds as $departmentId) {
            $expanded = array_merge(
                $expanded,
                DepartmentDescendantIds::includingSelf($departmentId, $departments),
            );
        }

        return array_values(array_unique(array_map('intval', $expanded)));
    }

    /**
     * Apply employee visibility scope through an Eloquent relation on a model.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereHas(Builder $query, ?User $user, int $companyId, string $relation = 'employee'): Builder
    {
        if ($user === null || $companyId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $allowedIds = self::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return $query;
        }

        if ($allowedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas($relation, function (Builder $q) use ($companyId, $allowedIds) {
            $q->where('employees.company_id', $companyId)
                ->whereIn('employees.department_id', $allowedIds);
        });
    }
}
