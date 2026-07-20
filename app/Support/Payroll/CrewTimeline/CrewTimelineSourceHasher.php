<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewAssignmentPhase;
use App\Models\PayrollPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewTimelineSourceHasher
{
    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     */
    public function hash(
        PayrollPeriod $period,
        ?CarbonInterface $cutoffDate,
        Collection $phases,
    ): string {
        $payload = [
            'period_id' => (int) $period->id,
            'period_start' => $period->start_date?->toDateString(),
            'period_end' => $period->end_date?->toDateString(),
            'cutoff_date' => $cutoffDate?->toDateString(),
            'phases' => $phases
                ->map(fn (CrewAssignmentPhase $phase): array => [
                    'employee_id' => (int) $phase->assignment?->employee_id,
                    'assignment_id' => (int) $phase->crew_assignment_id,
                    'phase_id' => (int) $phase->id,
                    'phase_code' => $phase->phase_code?->value,
                    'phase_status' => $phase->status?->value,
                    'actual_start' => $phase->actual_start_at?->toIso8601String(),
                    'actual_end' => $phase->actual_end_at?->toIso8601String(),
                ])
                ->sortBy([
                    ['employee_id', 'asc'],
                    ['assignment_id', 'asc'],
                    ['phase_id', 'asc'],
                ])
                ->values()
                ->all(),
        ];

        return hash('sha256', (string) json_encode($payload));
    }
}
