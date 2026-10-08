<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\User;

/**
 * Ownership and access helpers for provisional (DRAFT-*) employee records.
 *
 * Ownership is persisted via provisional_created_by — never inferred from
 * employee_no, name, department, or client-supplied identifiers alone.
 * Legacy drafts without a trusted owner fail closed for create-only access.
 *
 * Ownership never overrides an assigned department outside the user's current
 * visibility scope. The null-department exception exists only so creators can
 * finish a draft before a department is chosen.
 */
final class ProvisionalEmployeeAccess
{
    public static function isProvisional(Employee $employee): bool
    {
        return DraftEmployeeNumber::isDraft($employee->employee_no);
    }

    public static function isOwnedBy(?User $user, Employee $employee): bool
    {
        if ($user === null || $employee->provisional_created_by === null) {
            return false;
        }

        return (int) $employee->provisional_created_by === (int) $user->id;
    }

    /**
     * Creator may resume create?employee_id= only for their own provisional draft
     * in the current company when the department is still null or in-scope.
     * Finalized employees must use the normal profile route.
     */
    public static function canResumeCreate(?User $user, Employee $employee, int $companyId): bool
    {
        return self::canAccessOwnedProvisional($user, $employee, $companyId);
    }

    /**
     * Tightly scoped access for completing an owned provisional employee when
     * department visibility would otherwise deny NULL-department drafts.
     * Does not open drafts already assigned outside the user's scope.
     */
    public static function canAccessOwnedProvisional(?User $user, Employee $employee, int $companyId): bool
    {
        if ($user === null || $companyId <= 0) {
            return false;
        }

        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if (! self::isProvisional($employee) || ! self::isOwnedBy($user, $employee)) {
            return false;
        }

        return self::departmentAllowsOwnedProvisionalAccess($user, $employee, $companyId);
    }

    /**
     * Profile mutation visibility.
     *
     * Updaters always use normal department visibility (ownership never bypasses).
     * Create-capable users may also complete an owned provisional draft whose
     * department is still null (or already in-scope via normal visibility).
     */
    public static function canAccessForProfileMutation(?User $user, Employee $employee, int $companyId): bool
    {
        if ($user === null || $companyId <= 0) {
            return false;
        }

        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if (EmployeeVisibilityScope::canAccess($user, $employee, $companyId)) {
            return true;
        }

        if (! $user->can('employees.create')) {
            return false;
        }

        // Null-department owned provisional exception only — never unauthorized depts.
        return self::canAccessOwnedProvisional($user, $employee, $companyId);
    }

    /**
     * Owned provisional drafts are reachable when department is unset, or when
     * the assigned department remains inside the user's current visibility scope.
     */
    private static function departmentAllowsOwnedProvisionalAccess(
        ?User $user,
        Employee $employee,
        int $companyId,
    ): bool {
        $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return true;
        }

        if ($employee->department_id === null) {
            return true;
        }

        if ($allowedIds === []) {
            return false;
        }

        return in_array((int) $employee->department_id, $allowedIds, true);
    }
}
