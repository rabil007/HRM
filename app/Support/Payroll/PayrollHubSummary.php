<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;

final class PayrollHubSummary
{
    /**
     * @return array{
     *     total_periods: int,
     *     crew_periods: int,
     *     office_periods: int,
     *     incomplete_crew_runs: int
     * }
     */
    public static function forCompany(int $companyId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount('crewTimesheets');

        if ($dateFrom !== null && $dateFrom !== '') {
            $query->whereDate('end_date', '>=', $dateFrom);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $query->whereDate('start_date', '<=', $dateTo);
        }

        $periods = $query->get();

        $crewEmployeeCount = PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Crew);

        $incompleteCrewRuns = $periods
            ->filter(fn (PayrollPeriod $period) => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew)
            ->filter(fn (PayrollPeriod $period) => $period->status === PayrollPeriodStatus::Draft)
            ->filter(fn (PayrollPeriod $period) => $crewEmployeeCount > 0 && (int) $period->crew_timesheets_count < $crewEmployeeCount)
            ->count();

        return [
            'total_periods' => $periods->count(),
            'crew_periods' => $periods->filter(
                fn (PayrollPeriod $period) => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew,
            )->count(),
            'office_periods' => $periods->filter(
                fn (PayrollPeriod $period) => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Office,
            )->count(),
            'incomplete_crew_runs' => $incompleteCrewRuns,
        ];
    }
}
