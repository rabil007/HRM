<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\Historical\HistoricalAssignmentIntervalOverlap;
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
        $start = $this->operationalStartInstant($activeAssignment);

        if ($start instanceof CarbonInterface) {
            return $start->copy()->timezone($timezone)->toDateString();
        }

        $lifecycle = $activeAssignment->planned_join_at;

        if ($lifecycle instanceof CarbonInterface) {
            return $lifecycle->copy()->timezone($timezone)->toDateString();
        }

        return CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * Authoritative operational occupancy start (instant).
     *
     * Uses the earlier of lifecycle started_at and the earliest recorded phase
     * actual_start_at. Planned/forecast dates are never treated as occupancy.
     */
    public function operationalStartInstant(CrewAssignment $assignment): ?CarbonInterface
    {
        $candidates = [];

        if ($assignment->started_at instanceof CarbonInterface) {
            $candidates[] = $assignment->started_at;
        }

        $earliestActual = $this->earliestActualPhaseStart($assignment);

        if ($earliestActual instanceof CarbonInterface) {
            $candidates[] = $earliestActual;
        }

        if ($candidates === []) {
            return null;
        }

        $earliest = $candidates[0];

        foreach (array_slice($candidates, 1) as $candidate) {
            if ($candidate->lt($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    /**
     * Authoritative operational occupancy end (instant).
     *
     * Active assignments are open-ended (null). Non-active assignments prefer
     * closed_at when present. Without closed_at, an unfinished phase cannot
     * establish a reliable end (returns null for callers to fail safe).
     * Otherwise the latest phase actual_end_at is used.
     */
    public function operationalEndInstant(CrewAssignment $assignment): ?CarbonInterface
    {
        if ($assignment->status === CrewAssignmentStatus::Active) {
            return null;
        }

        // closed_at is authoritative for completed / cancelled occupancy ends.
        if ($assignment->closed_at instanceof CarbonInterface) {
            return $assignment->closed_at;
        }

        $phases = $this->phasesFor($assignment);

        foreach ($phases as $phase) {
            if ($phase instanceof CrewAssignmentPhase
                && $phase->actual_start_at !== null
                && $phase->actual_end_at === null) {
                // Incomplete historical record without closed_at — unreliable.
                return null;
            }
        }

        $latestEnd = null;

        foreach ($phases as $phase) {
            if (! $phase instanceof CrewAssignmentPhase || $phase->actual_end_at === null) {
                continue;
            }

            if ($latestEnd === null || $phase->actual_end_at->gt($latestEnd)) {
                $latestEnd = $phase->actual_end_at;
            }
        }

        return $latestEnd ?? $this->operationalStartInstant($assignment);
    }

    /**
     * Completed / cancelled occupancy whose end cannot be established reliably
     * (unfinished phase and no closed_at). Callers must fail safe rather than skip.
     */
    public function hasUnreliableCompletedOccupancy(CrewAssignment $assignment): bool
    {
        if ($assignment->status === CrewAssignmentStatus::Active) {
            return false;
        }

        if ($assignment->closed_at instanceof CarbonInterface) {
            return false;
        }

        foreach ($this->phasesFor($assignment) as $phase) {
            if ($phase instanceof CrewAssignmentPhase
                && $phase->actual_start_at !== null
                && $phase->actual_end_at === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authoritative operational occupancy interval from lifecycle + phase actuals.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface|null}|null
     */
    public function operationalOccupancyInterval(CrewAssignment $assignment): ?array
    {
        $start = $this->operationalStartInstant($assignment);

        if ($start === null) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $this->operationalEndInstant($assignment),
        ];
    }

    /**
     * Day-grain occupancy window in the company timezone.
     *
     * @return array{start: string, end: string|null}|null
     */
    public function operationalOccupancyDateWindow(CrewAssignment $assignment, string $timezone): ?array
    {
        $interval = $this->operationalOccupancyInterval($assignment);

        if ($interval === null) {
            return null;
        }

        return [
            'start' => $interval['start']->copy()->timezone($timezone)->toDateString(),
            'end' => $interval['end']?->copy()->timezone($timezone)->toDateString(),
        ];
    }

    /**
     * Convert date-only planning / conflict bounds into a half-open instant interval.
     *
     * @return array{start: CarbonInterface, end: CarbonInterface|null}
     */
    public function calendarDayReservationInterval(
        string $startDate,
        ?string $endDate,
        string $timezone,
    ): array {
        return HistoricalAssignmentIntervalOverlap::calendarDayReservationInterval(
            $startDate,
            $endDate,
            $timezone,
        );
    }

    /**
     * Whether a candidate instant interval overlaps this assignment's operational occupancy.
     *
     * Returns null when occupancy cannot be established (caller should fail safe).
     */
    public function overlapsOperationalOccupancy(
        CarbonInterface $candidateStart,
        ?CarbonInterface $candidateEnd,
        CrewAssignment $assignment,
    ): ?bool {
        if ($this->hasUnreliableCompletedOccupancy($assignment)) {
            return null;
        }

        $interval = $this->operationalOccupancyInterval($assignment);

        if ($interval === null) {
            return false;
        }

        return HistoricalAssignmentIntervalOverlap::intervalsOverlap(
            $candidateStart,
            $candidateEnd,
            $interval['start'],
            $interval['end'],
        );
    }

    private function earliestActualPhaseStart(CrewAssignment $assignment): ?CarbonInterface
    {
        $earliest = null;

        foreach ($this->phasesFor($assignment) as $phase) {
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
     * @return iterable<int, CrewAssignmentPhase>
     */
    private function phasesFor(CrewAssignment $assignment): iterable
    {
        if ($assignment->relationLoaded('phases')) {
            return $assignment->phases;
        }

        return CrewAssignmentPhase::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->get(['id', 'crew_assignment_id', 'actual_start_at', 'actual_end_at']);
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
