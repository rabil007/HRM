<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\User;

final class AuthorizeEmployeeProfileUpdate
{
    /**
     * Users with employees.update may edit any in-scope employee profile
     * (visibility is enforced separately in the controller).
     *
     * Users with only employees.create may complete the initial profile for
     * provisional DRAFT-* records they own (provisional_created_by).
     * Legacy drafts without a trusted owner fail closed.
     */
    public static function allows(?User $user, Employee $employee): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can('employees.update')) {
            return true;
        }

        if (! $user->can('employees.create')) {
            return false;
        }

        if (! ProvisionalEmployeeAccess::isProvisional($employee)) {
            return false;
        }

        return ProvisionalEmployeeAccess::isOwnedBy($user, $employee);
    }
}
