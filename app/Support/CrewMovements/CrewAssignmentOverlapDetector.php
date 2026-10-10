<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CrewAssignmentOverlapDetector
{
    /**
     * Determine if a candidate date window overlaps an active assignment.
     */
    public function overlapsActive(
        string $candidateStart,
        ?string $candidateEnd,
        CrewAssignment $activeAssignment,
        string $timezone,
    ): bool {
        $activeStart = $this->operationalStartDate($activeAssignment, $timezone);
        $activeEnd = $activeAssignment->planned_signoff_at?->copy()->timezone($timezone)->toDateString();

        // If active assignment has no signoff, it is actively ongoing; any candidate plan unconditionally conflicts.
        if ($activeEnd === null) {
            return true;
        }

        if ($candidateEnd === null) {
            return $candidateStart <= $activeEnd;
        }

        return $candidateStart <= $activeEnd && $activeStart <= $candidateEnd;
    }

    /**
     * Calendar date when this assignment began occupying the employee.
     *
     * Lifecycle started_at stays at Start Assignment time, including when an
     * initial arrival is reconciled earlier. Occupancy follows the earlier of
     * that lifecycle timestamp and the earliest phase actual start.
     */
    public function operationalStartDate(CrewAssignment $activeAssignment, string $timezone): string
    {
        $candidates = [];

        $lifecycle = $activeAssignment->started_at ?? $activeAssignment->planned_join_at;

        if ($lifecycle instanceof CarbonInterface) {
            $candidates[] = $lifecycle->copy()->timezone($timezone)->toDateString();
        }

        $earliestActual = $this->earliestActualPhaseStart($activeAssignment);

        if ($earliestActual instanceof CarbonInterface) {
            $candidates[] = $earliestActual->copy()->timezone($timezone)->toDateString();
        }

        if ($candidates === []) {
            return CarbonImmutable::now($timezone)->toDateString();
        }

        sort($candidates);

        return $candidates[0];
    }

    private function earliestActualPhaseStart(CrewAssignment $assignment): ?CarbonInterface
    {
        $phases = $assignment->relationLoaded('phases')
            ? $assignment->phases
            : CrewAssignmentPhase::query()
                ->where('company_id', $assignment->company_id)
                ->where('crew_assignment_id', $assignment->id)
                ->get(['id', 'crew_assignment_id', 'actual_start_at']);

        $earliest = null;

        foreach ($phases as $phase) {
            if (! $phase instanceof CrewAssignmentPhase || $phase->actual_start_at === null) {
                continue;
            }

            if ($earliest === null || $phase->actual_start_at->lt($earliest)) {
                $earliest = $phase->actual_start_at;
            }
        }

        return $earliest;
    }

    /**
     * Determine if a candidate date window overlaps a planned crew planning assignment.
     */
    public function overlapsPlannedPlanningAssignment(
        string $candidateStart,
        ?string $candidateEnd,
        CrewPlanningAssignment $plan,
    ): bool {
        $pStart = ($plan->planned_arrival_date ?? $plan->planned_join_date)?->toDateString();

        if ($pStart === null) {
            return false;
        }

        $effectivePEnd = $plan->planned_leave_date?->toDateString() ?? $pStart;

        if ($candidateEnd === null) {
            return $candidateStart <= $effectivePEnd;
        }

        return max($candidateStart, $pStart) <= min($candidateEnd, $effectivePEnd);
    }

    /**
     * Resolve date window [start, end] for a relief plan (CrewAssignment or CrewPlanningAssignment).
     *
     * @return array{start: string|null, end: string|null}
     */
    public function dateWindowForPlan(CrewAssignment|CrewPlanningAssignment $plan, string $timezone): array
    {
        if ($plan instanceof CrewAssignment) {
            $start = ($plan->planned_arrival_at ?? $plan->planned_join_at)?->copy()->timezone($timezone)->toDateString();
            $end = $plan->planned_signoff_at?->copy()->timezone($timezone)->toDateString();

            return ['start' => $start, 'end' => $end];
        }

        $start = ($plan->planned_arrival_date ?? $plan->planned_join_date)?->toDateString();
        $end = ($plan->planned_leave_date ?? $plan->planned_signoff_date)?->toDateString();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Determine if two date windows overlap.
     */
    public function windowsOverlap(
        string $startA,
        ?string $endA,
        string $startB,
        ?string $endB,
    ): bool {
        if ($endA === null && $endB === null) {
            return true;
        }

        if ($endA === null) {
            return $startA <= $endB;
        }

        if ($endB === null) {
            return $startB <= $endA;
        }

        return max($startA, $startB) <= min($endA, $endB);
    }
}
