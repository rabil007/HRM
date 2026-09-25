<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Models\User;

final class PayrollPeriodListResource
{
    /**
     * @param  array{crew: int, office: int, daily_crew: int}  $employeeCountsByCategory
     * @param  array{
     *     filled_daily_timesheets: int,
     *     visible_crew_timesheets: int,
     *     daily_payroll_records: int,
     *     daily_payroll_records_with_timesheet: int
     * }|null  $visibleCrewStats
     * @return array<string, mixed>
     */
    public static function toArray(
        PayrollPeriod $period,
        array $employeeCountsByCategory,
        bool $includeFinancial = true,
        ?User $user = null,
        ?array $visibleCrewStats = null,
    ): array {
        $category = $period->payroll_category ?? PayrollCategory::Crew;
        $employeeCount = $employeeCountsByCategory[$category->value] ?? 0;

        if ($category === PayrollCategory::Crew) {
            $stats = $visibleCrewStats ?? PayrollPeriodVisibleCrewStats::forPeriods(
                [(int) $period->id],
                (int) $period->company_id,
                $user,
            )[(int) $period->id] ?? [
                'filled_daily_timesheets' => 0,
                'visible_crew_timesheets' => 0,
                'daily_payroll_records' => 0,
                'daily_payroll_records_with_timesheet' => 0,
            ];

            [$timesheetEligibleCount, $filledCount] = PayrollPeriodVisibleCrewStats::progressCounts(
                $stats,
                (int) ($employeeCountsByCategory['daily_crew'] ?? 0),
            );
        } else {
            $timesheetEligibleCount = 0;
            $filledCount = 0;
        }

        return [
            ...PayrollPeriodResource::toArray($period, null, $includeFinancial, $user),
            'run_label' => $period->name.' · '.$category->label(),
            'employee_count' => $employeeCount,
            'timesheet_eligible_count' => $timesheetEligibleCount,
            'timesheets_filled_count' => $filledCount,
            'timesheets_progress_label' => $category === PayrollCategory::Crew
                ? ($timesheetEligibleCount > 0
                    ? "{$filledCount}/{$timesheetEligibleCount}"
                    : '0/0')
                : null,
            'supports_timesheets' => $category === PayrollCategory::Crew,
        ];
    }
}
