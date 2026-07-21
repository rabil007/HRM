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
        $companyId = (int) $period->company_id;
        [$periodStartUtc, $periodEndUtc] = $this->utcBoundaries($period, $effectiveEnd);

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
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function issuePhases(
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
    ): Collection {
        $companyId = (int) $period->company_id;
        [$periodStartUtc, $periodEndUtc] = $this->utcBoundaries($period, $effectiveEnd);

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

    public function effectiveEndDate(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
    ): CarbonImmutable {
        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);
        $periodEnd = CarbonImmutable::parse($period->end_date->toDateString(), $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $candidates = [$periodEnd, $today];

        if ($cutoffDate !== null) {
            $candidates[] = CarbonImmutable::parse($cutoffDate->toDateString(), $timezone)->startOfDay();
        }

        $earliest = $candidates[0];

        foreach ($candidates as $candidate) {
            if ($candidate->lt($earliest)) {
                $earliest = $candidate;
            }
        }

        $periodStart = CarbonImmutable::parse($period->start_date->toDateString(), $timezone)->startOfDay();

        if ($earliest->lt($periodStart)) {
            return $periodStart;
        }

        return $earliest;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcBoundaries(PayrollPeriod $period, CarbonInterface $effectiveEnd): array
    {
        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);

        $periodStartUtc = CarbonImmutable::parse($period->start_date->toDateString(), $timezone)
            ->startOfDay()
            ->utc();
        $periodEndUtc = CarbonImmutable::parse($effectiveEnd->toDateString(), $timezone)
            ->endOfDay()
            ->utc();

        return [$periodStartUtc, $periodEndUtc];
    }
}
