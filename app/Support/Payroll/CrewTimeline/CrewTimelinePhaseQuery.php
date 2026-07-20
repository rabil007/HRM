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
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function overlappingPhases(
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
    ): Collection {
        $companyId = (int) $period->company_id;
        $periodStart = CarbonImmutable::parse($period->start_date->toDateString(), 'UTC')->startOfDay();
        $periodEnd = CarbonImmutable::parse($effectiveEnd->toDateString(), 'UTC')->endOfDay();

        return CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereNotNull('actual_start_at')
            ->whereIn('status', ['active', 'completed'])
            ->where('actual_start_at', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('actual_end_at')
                    ->orWhere('actual_end_at', '>=', $periodStart);
            })
            ->whereHas('assignment', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId);
            })
            ->with(['assignment'])
            ->orderBy('actual_start_at')
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
}
