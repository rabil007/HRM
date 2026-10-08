<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\User;

final class AuthorizeEmployeeProfileUpdate
{
    /**
     * Users with employees.update may edit any in-scope employee profile.
     * Users with only employees.create may complete the initial profile for
     * provisional DRAFT-* records they can access (same company / visibility).
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

        return DraftEmployeeNumber::isDraft($employee->employee_no);
    }
}
