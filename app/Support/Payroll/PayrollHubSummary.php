<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class PayrollHubSummary
{
    /**
     * @param  list<string>  $months
     * @return array{
     *     total_periods: int,
     *     crew_periods: int,
     *     office_periods: int,
     *     incomplete_crew_runs: int
     * }
     */
    public static function forCompany(
        int $companyId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $months = [],
        ?User $user = null,
    ): array {
        $includeFinancial = (bool) ($user?->can('payroll.periods.view'));

        $query = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount('crewTimesheets');

        if (! $includeFinancial) {
            $query->where('payroll_category', PayrollCategory::Crew);
        }

        if ($months !== []) {
            $query->where(function (Builder $dateQuery) use ($months): void {
                foreach ($months as $month) {
                    $start = CarbonImmutable::parse($month.'-01')->startOfMonth()->toDateString();
                    $end = CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString();
                    $dateQuery->orWhere(function (Builder $mQuery) use ($start, $end): void {
                        $mQuery->whereDate('end_date', '>=', $start)
                            ->whereDate('start_date', '<=', $end);
                    });
                }
            });
        } else {
            if ($dateFrom !== null && $dateFrom !== '') {
                $query->whereDate('end_date', '>=', $dateFrom);
            }

            if ($dateTo !== null && $dateTo !== '') {
                $query->whereDate('start_date', '<=', $dateTo);
            }
        }

        $periods = $query->get();

        $crewEmployeeQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($crewEmployeeQuery, $user, $companyId);
        }

        $crewEmployeeCount = $crewEmployeeQuery->count();

        $crewPeriods = $periods->filter(
            fn (PayrollPeriod $period) => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Crew,
        );

        $incompleteCrewRuns = $crewPeriods
            ->filter(fn (PayrollPeriod $period) => $period->status === PayrollPeriodStatus::Draft)
            ->filter(fn (PayrollPeriod $period) => $crewEmployeeCount > 0 && (int) $period->crew_timesheets_count < $crewEmployeeCount)
            ->count();

        $crewPeriodCount = $crewPeriods->count();

        return [
            'total_periods' => $includeFinancial ? $periods->count() : $crewPeriodCount,
            'crew_periods' => $crewPeriodCount,
            'office_periods' => $includeFinancial
                ? $periods->filter(
                    fn (PayrollPeriod $period) => ($period->payroll_category ?? PayrollCategory::Crew) === PayrollCategory::Office,
                )->count()
                : 0,
            'incomplete_crew_runs' => $incompleteCrewRuns,
        ];
    }
}
