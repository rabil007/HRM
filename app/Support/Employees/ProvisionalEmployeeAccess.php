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
     * in the current company. Finalized employees must use the normal profile route.
     */
    public static function canResumeCreate(?User $user, Employee $employee, int $companyId): bool
    {
        if ($user === null || $companyId <= 0) {
            return false;
        }

        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if (! self::isProvisional($employee)) {
            return false;
        }

        return self::isOwnedBy($user, $employee);
    }

    /**
     * Tightly scoped access for completing an owned provisional employee when
     * department visibility would otherwise deny NULL-department drafts.
     * Does not broadly open all provisional employees.
     */
    public static function canAccessOwnedProvisional(?User $user, Employee $employee, int $companyId): bool
    {
        return self::canResumeCreate($user, $employee, $companyId);
    }

    /**
     * Profile mutation visibility: normal department visibility, or owned provisional.
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

        return self::canAccessOwnedProvisional($user, $employee, $companyId);
    }
}
