<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;

final class PayrollPeriodBoardSummary
{
    /**
     * @return array{
     *     employee_count: int,
     *     filled_count: int,
     *     progress_percent: int
     * }
     */
    public static function forPeriod(PayrollPeriod $period, int $employeeCount): array
    {
        $filledCount = ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew
            ? CrewTimesheet::query()
                ->where('company_id', $period->company_id)
                ->where('period_id', $period->id)
                ->count()
            : 0;

        $progressPercent = $employeeCount > 0
            ? (int) round(($filledCount / $employeeCount) * 100)
            : 0;

        return [
            'employee_count' => $employeeCount,
            'filled_count' => $filledCount,
            'progress_percent' => $progressPercent,
        ];
    }
}
