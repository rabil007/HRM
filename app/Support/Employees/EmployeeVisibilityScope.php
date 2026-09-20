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
     * @deprecated No-op. Access decisions no longer use cross-request memoization.
     */
    public static function clearCache(): void
    {
        // Intentionally empty — retained for backward compatibility with existing callers.
    }

    /**
     * Whether the user has unrestricted employee access in the company (all departments).
     */
    public static function hasUnrestrictedAccess(?User $user, int $companyId): bool
    {
        return self::allowedDepartmentIds($user, $companyId) === null;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public static function filterAuthorizedEmployeeIds(?User $user, int $companyId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        if ($user === null || $companyId <= 0) {
            return [];
        }

        return self::apply(
            Employee::query()->whereIn('employees.id', $employeeIds),
            $user,
            $companyId,
        )
            ->pluck('employees.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
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

        $employee = self::resolveEmployeeForAccess($employee, $companyId);

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
     * Determine whether a user may access an Employee by ID.
     */
    public static function canAccessId(?User $user, int $employeeId, int $companyId, bool $allowSelf = false): bool
    {
        if ($user === null || $companyId <= 0 || $employeeId <= 0) {
            return false;
        }

        if (self::hasUnrestrictedAccess($user, $companyId)) {
            return true;
        }

        $employee = Employee::withTrashed()
            ->whereKey($employeeId)
            ->where('company_id', $companyId)
            ->first(['id', 'company_id', 'department_id', 'user_id']);

        if ($employee === null) {
            return false;
        }

        return self::canAccess($user, $employee, $companyId, $allowSelf);
    }

    /**
     * Ensure visibility checks use persisted employee scope columns even when
     * the model was eager-loaded with a partial column selection.
     */
    private static function resolveEmployeeForAccess(Employee $employee, int $companyId): Employee
    {
        if ($employee->id === null) {
            return $employee;
        }

        $attributes = $employee->getAttributes();
        $required = ['company_id', 'department_id', 'user_id'];
        $missing = array_filter(
            $required,
            fn (string $key): bool => ! array_key_exists($key, $attributes),
        );

        if ($missing === []) {
            return $employee;
        }

        $fresh = Employee::withTrashed()
            ->whereKey($employee->id)
            ->where('company_id', $companyId)
            ->first(array_merge(['id'], $required));

        return $fresh ?? $employee;
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
            return [];
        }

        // Owner role is protected and always all
        if ($roles->contains(fn (Role $r) => $r->name === 'Owner')) {
            return null;
        }

        // If any role has SCOPE_ALL, user has unrestricted employee access
        if ($roles->contains(fn (Role $r) => $r->employee_visibility_scope === Role::SCOPE_ALL)) {
            return null;
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
            return [];
        }

        return self::expandDepartmentsWithDescendants($companyId, $configuredDepartmentIds);
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
