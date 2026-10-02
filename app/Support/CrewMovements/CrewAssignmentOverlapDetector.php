<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use Carbon\CarbonImmutable;

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
        $activeStart = ($activeAssignment->started_at ?? $activeAssignment->planned_join_at)?->copy()->timezone($timezone)->toDateString()
            ?? CarbonImmutable::now($timezone)->toDateString();
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
     * Determine if a candidate date window overlaps a planned assignment.
     */
    public function overlapsPlanned(
        string $candidateStart,
        ?string $candidateEnd,
        CrewAssignment $plannedAssignment,
        string $timezone,
    ): bool {
        $pStart = ($plannedAssignment->planned_arrival_at ?? $plannedAssignment->planned_join_at)?->copy()->timezone($timezone)->toDateString();

        if ($pStart === null) {
            return false;
        }

        $effectivePEnd = $plannedAssignment->planned_signoff_at?->copy()->timezone($timezone)->toDateString() ?? $pStart;

        if ($candidateEnd === null) {
            return $candidateStart <= $effectivePEnd;
        }

        return max($candidateStart, $pStart) <= min($candidateEnd, $effectivePEnd);
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
