<?php

namespace App\Support\Employees;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Exists rule for operational employee_id fields: current company + active status + visibility scope.
 */
final class ActiveCompanyEmployeeRule
{
    public static function exists(int $companyId, ?User $user = null): Exists
    {
        return Rule::exists('employees', 'id')->where(function ($query) use ($companyId, $user) {
            $query
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereNull('deleted_at');

            if ($user !== null) {
                EmployeeVisibilityScope::apply($query, $user, $companyId);
            }
        });
    }
}
