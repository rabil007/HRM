<?php

namespace App\Support\Employees;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Exists rule for operational employee_id fields: current company + active status.
 */
final class ActiveCompanyEmployeeRule
{
    public static function exists(int $companyId): Exists
    {
        return Rule::exists('employees', 'id')->where(fn ($query) => $query
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNull('deleted_at'));
    }
}
