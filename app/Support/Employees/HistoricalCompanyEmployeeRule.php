<?php

namespace App\Support\Employees;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Exists rule for historical employee_id fields: current company + not soft-deleted + visibility scope.
 * Allows employees regardless of HR status (active, inactive, terminated, on_leave).
 */
final class HistoricalCompanyEmployeeRule
{
    public static function exists(int $companyId, ?User $user = null): Exists
    {
        return Rule::exists('employees', 'id')->where(function ($query) use ($companyId, $user) {
            $query
                ->where('company_id', $companyId)
                ->whereNull('deleted_at');

            if ($user === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

            if ($allowedIds === []) {
                $query->whereRaw('1 = 0');
            } elseif ($allowedIds !== null) {
                $query->whereIn('department_id', $allowedIds);
            }
        });
    }
}
