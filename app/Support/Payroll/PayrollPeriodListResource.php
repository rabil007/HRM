<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;

final class PayrollPeriodListResource
{
    /**
     * @param  array<string, int>  $employeeCountsByCategory
     * @return array<string, mixed>
     */
    public static function toArray(PayrollPeriod $period, array $employeeCountsByCategory): array
    {
        $category = $period->payroll_category ?? PayrollCategory::Crew;
        $employeeCount = $employeeCountsByCategory[$category->value] ?? 0;
        $filledCount = $category === PayrollCategory::Crew
            ? (int) ($period->crew_timesheets_count ?? $period->crewTimesheets()->count())
            : 0;

        return [
            ...PayrollPeriodResource::toArray($period),
            'run_label' => $period->name.' · '.$category->label(),
            'employee_count' => $employeeCount,
            'timesheets_filled_count' => $filledCount,
            'timesheets_progress_label' => $category === PayrollCategory::Crew
                ? ($employeeCount > 0 ? "{$filledCount}/{$employeeCount}" : '0/0')
                : null,
            'supports_timesheets' => $category === PayrollCategory::Crew,
        ];
    }
}
