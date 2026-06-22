<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class CrewPayrollEmployeeQuery
{
    public static function activeCrewCount(int $companyId): int
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereHas('currentContract', function (Builder $contractQuery) {
                $contractQuery->where('payroll_category', PayrollCategory::Crew);
            })
            ->count();
    }
}
