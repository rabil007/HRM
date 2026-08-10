<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Contracts\ContractSalaryStructureFilter;
use Illuminate\Database\Eloquent\Builder;

final class PayrollEmployeeQuery
{
    public static function activeCount(int $companyId, PayrollCategory $category): int
    {
        return self::activeQuery($companyId, $category)->count();
    }

    public static function activeDailyCrewCount(int $companyId): int
    {
        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->whereHas('currentContract', function (Builder $contractQuery): void {
                $contractQuery->where('payroll_category', PayrollCategory::Crew);
                ContractSalaryStructureFilter::apply(
                    $contractQuery,
                    ContractSalaryStructureFilter::DAILY,
                );
            })
            ->count();
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

    /**
     * @return Builder<Employee>
     */
    public static function forPeriod(PayrollPeriod $period, PayrollCategory $category): Builder
    {
        $companyId = (int) $period->company_id;
        $asOfDate = $period->start_date->toDateString();

        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->whereHas('contracts', function (Builder $contractQuery) use ($category, $companyId, $asOfDate): void {
                $contractQuery
                    ->where('company_id', $companyId)
                    ->where('payroll_category', $category)
                    ->whereDate('start_date', '<=', $asOfDate)
                    ->where(function (Builder $dateQuery) use ($asOfDate): void {
                        $dateQuery->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $asOfDate);
                    });
            });
    }
}
