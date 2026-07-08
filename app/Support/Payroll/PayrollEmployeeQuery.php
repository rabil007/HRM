<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class PayrollEmployeeQuery
{
    public static function activeCount(int $companyId, PayrollCategory $category): int
    {
        return self::activeQuery($companyId, $category)->count();
    }

    /**
     * @return Builder<Employee>
     */
    public static function activeQuery(int $companyId, PayrollCategory $category): Builder
    {
        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->whereHas('currentContract', function (Builder $contractQuery) use ($category) {
                $contractQuery->where('payroll_category', $category);
            });
    }
}
