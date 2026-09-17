<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewAssignmentPhase;
use App\Models\PayrollPeriod;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewTimelinePhaseQuery
{
    public function __construct(
        private readonly DailyCrewPayablePhaseEligibility $payablePhaseEligibility,
    ) {}

    /**
     * Payable-allocation query: only phases with an actual start contribute
     * payable days. Period boundaries are resolved in the company timezone and
     * converted to UTC so phases that fall on a local payroll date but cross a
     * UTC midnight boundary are not incorrectly excluded.
     *
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function overlappingPhases(
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
    ): Collection {
        $boundaries = $this->utcBoundaries($period, $effectiveEnd);

        if ($boundaries === null) {
            return collect();
        }

        [$periodStartUtc, $periodEndUtc] = $boundaries;
        $companyId = (int) $period->company_id;

        return CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereNotNull('actual_start_at')
            ->whereIn('status', ['active', 'completed'])
            ->where('actual_start_at', '<=', $periodEndUtc)
            ->where(function ($query) use ($periodStartUtc): void {
                $query->whereNull('actual_end_at')
                    ->orWhere('actual_end_at', '>=', $periodStartUtc);
            })
            ->whereHas('assignment', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->with(['assignment'])
            ->orderBy('actual_start_at')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Issue-detection query: a superset of the payable query that also includes
     * otherwise-relevant phases that are missing an actual start (overlapping by
     * their planned window). These must reach issue detection so a blocking
     * `missing_actual_start` warning is raised instead of the phase being
     * silently dropped.
     *
     * Planned `planned_start_at` / `planned_end_at` values here are discovery
     * hints only: they locate a phase whose `actual_start_at` is missing so a
     * 0-day warning can be generated. They are never payroll allocation inputs
     * and must not be converted into payable days.
     *
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function issuePhases(
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
    ): Collection {
        $boundaries = $this->utcBoundaries($period, $effectiveEnd);

        if ($boundaries === null) {
            return collect();
        }

        [$periodStartUtc, $periodEndUtc] = $boundaries;
        $companyId = (int) $period->company_id;

        return CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'completed'])
            ->where(function ($query) use ($periodStartUtc, $periodEndUtc): void {
                $query->where(function ($actual) use ($periodStartUtc, $periodEndUtc): void {
                    $actual->whereNotNull('actual_start_at')
                        ->where('actual_start_at', '<=', $periodEndUtc)
                        ->where(function ($inner) use ($periodStartUtc): void {
                            $inner->whereNull('actual_end_at')
                                ->orWhere('actual_end_at', '>=', $periodStartUtc);
                        });
                })->orWhere(function ($planned) use ($periodStartUtc, $periodEndUtc): void {
                    $planned->whereNull('actual_start_at')
                        ->whereNotNull('planned_start_at')
                        ->where('planned_start_at', '<=', $periodEndUtc)
                        ->where(function ($inner) use ($periodStartUtc): void {
                            $inner->whereNull('planned_end_at')
                                ->orWhere('planned_end_at', '>=', $periodStartUtc);
                        });
                });
            })
            ->whereHas('assignment', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->with(['assignment'])
            ->orderByRaw('actual_start_at is null')
            ->orderBy('actual_start_at')
            ->orderBy('planned_start_at')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Safe payroll allocation end: the earliest of payroll period end,
     * company-local today, and an explicit cutoff when supplied.
     *
     * Future payable days are never generated. A user cutoff after today
     * cannot authorize dates that have not occurred.
     */
    public function effectiveEndDate(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);
        $periodEnd = CarbonImmutable::parse($period->end_date->toDateString(), $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $effectiveEnd = $periodEnd->lt($today) ? $periodEnd : $today;

        if ($cutoffDate !== null) {
            $cutoff = CarbonImmutable::parse($cutoffDate->toDateString(), $timezone)->startOfDay();

            if ($cutoff->lt($effectiveEnd)) {
                $effectiveEnd = $cutoff;
            }
        }

        return $effectiveEnd;
    }

    /**
     * Resolves the effective preparation cutoff ("as-of") date.
     *
     * For open Daily Crew payable phases overlapping the period, the effective
     * cutoff advances with company-local today up to period end or explicit cutoff
     * ($effectiveEnd). Monthly Crew and excluded phases do not advance the cutoff.
     *
     * For completed historical phases, the effective cutoff is bounded by the
     * latest actual movement date of the closed timeline, avoiding unnecessary
     * daily invalidation when wall-clock time advances.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     */
    public function resolveEffectiveCutoffDate(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
        Collection $phases,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);
        $effectiveEnd = $this->effectiveEndDate($period, $cutoffDate);
        $periodStart = CarbonImmutable::parse($period->start_date->toDateString(), $timezone)->startOfDay();

        if ($this->payablePhaseEligibility->hasOpenPayableDailyPhase($period, $effectiveEnd, $phases)) {
            return $effectiveEnd;
        }

        $latestClosedActualDate = null;

        foreach ($phases as $phase) {
            if ($phase->actual_start_at === null) {
                continue;
            }

            $phaseStart = CarbonImmutable::parse($phase->actual_start_at, $timezone)->startOfDay();

            if ($phaseStart->gt($effectiveEnd)) {
                continue;
            }

            if ($phase->actual_end_at === null) {
                continue;
            }

            $phaseEnd = CarbonImmutable::parse($phase->actual_end_at, $timezone)->startOfDay();

            if ($phaseEnd->gt($effectiveEnd)) {
                continue;
            }

            if ($latestClosedActualDate === null || $phaseEnd->gt($latestClosedActualDate)) {
                $latestClosedActualDate = $phaseEnd;
            }
        }

        if ($latestClosedActualDate === null) {
            return $periodStart->lt($effectiveEnd) ? $periodStart : $effectiveEnd;
        }

        $boundedLatest = $latestClosedActualDate->lt($periodStart) ? $periodStart : $latestClosedActualDate;

        return $boundedLatest->lt($effectiveEnd) ? $boundedLatest : $effectiveEnd;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function utcBoundaries(PayrollPeriod $period, CarbonInterface $effectiveEnd): ?array
    {
        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);

        $periodStart = CarbonImmutable::parse($period->start_date->toDateString(), $timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($effectiveEnd->toDateString(), $timezone)->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            return null;
        }

        return [$periodStart->utc(), $periodEnd->endOfDay()->utc()];
    }
}
