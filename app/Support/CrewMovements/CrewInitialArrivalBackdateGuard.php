<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\Historical\HistoricalAssignmentIntervalOverlap;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Narrow exception for recording an actual arrival before the initial P0 phase start.
 *
 * Only the first, still-active Pre-Mobilisation phase on an assignment with no later
 * movement history may be reconciled backwards. Assignment created_at and lifecycle
 * started_at are preserved; subsequent phases keep strict chronology.
 */
final class CrewInitialArrivalBackdateGuard
{
    public function __construct(
        private readonly CrewAssignmentOverlapDetector $overlapDetector = new CrewAssignmentOverlapDetector,
    ) {}

    public static function isEligible(CrewAssignment $assignment, ?CrewAssignmentPhase $current = null): bool
    {
        $current ??= $assignment->currentPhase;

        if ($current === null) {
            return false;
        }

        if ($current->phase_code !== CrewPhaseCode::PreMobilisation) {
            return false;
        }

        if ($current->status !== CrewPhaseStatus::Active) {
            return false;
        }

        if ($current->actual_end_at !== null) {
            return false;
        }

        if ((int) $current->sequence !== 1) {
            return false;
        }

        $assignment->loadMissing('phases');

        return $assignment->phases->count() === 1
            && (int) $assignment->current_phase_id === (int) $current->id;
    }

    public function reconcileIfNeeded(
        CrewAssignment $assignment,
        CrewAssignmentPhase $current,
        CarbonInterface $occurredAt,
        ?int $actorId,
    ): void {
        if ($current->actual_start_at === null || ! $occurredAt->lt($current->actual_start_at)) {
            return;
        }

        if (! self::isEligible($assignment, $current)) {
            throw CrewMovementException::make(
                'This date cannot be before the current phase started.',
                'occurred_at_before_phase_start',
            );
        }

        $previousStart = $current->actual_start_at->copy();

        $this->assertDoesNotOverlapOtherAssignments($assignment, $occurredAt, $previousStart);
        $this->assertDoesNotOverlapCrewPlanning($assignment, $occurredAt, $previousStart);

        $preservedStartedAt = $assignment->started_at?->toDateTimeString();
        $preservedCreatedAt = $assignment->created_at?->toDateTimeString();

        $current->update([
            'actual_start_at' => $occurredAt,
        ]);

        activity()
            ->performedOn($assignment)
            ->causedBy($actorId)
            ->withProperties([
                'event' => 'crew_initial_arrival_backdated',
                'company_id' => $assignment->company_id,
                'assignment_id' => $assignment->id,
                'phase_id' => $current->id,
                'previous_phase_actual_start_at' => $previousStart->toDateTimeString(),
                'adjusted_phase_actual_start_at' => $occurredAt->toDateTimeString(),
                'arrival_occurred_at' => $occurredAt->toDateTimeString(),
                'assignment_started_at_preserved' => $preservedStartedAt,
                'assignment_created_at_preserved' => $preservedCreatedAt,
            ])
            ->tap(function ($activity) use ($assignment): void {
                $activity->company_id = $assignment->company_id;
            })
            ->log('Initial pre-mobilisation phase reconciled for backdated arrival');
    }

    private function assertDoesNotOverlapOtherAssignments(
        CrewAssignment $assignment,
        CarbonInterface $occurredAt,
        CarbonInterface $previousPhaseStart,
    ): void {
        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);

        $activeOther = CrewAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->where('employee_id', $assignment->employee_id)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereKeyNot($assignment->id)
            ->lockForUpdate()
            ->first(['id', 'assignment_no']);

        if ($activeOther !== null) {
            throw CrewMovementException::make(
                sprintf(
                    'This arrival date conflicts with another active assignment (%s). Resolve the conflicting assignment before recording arrival.',
                    $activeOther->assignment_no,
                ),
                'backdated_arrival_active_conflict',
            );
        }

        $historical = CrewAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->where('employee_id', $assignment->employee_id)
            ->whereIn('status', [
                CrewAssignmentStatus::Completed,
                CrewAssignmentStatus::Cancelled,
            ])
            ->whereKeyNot($assignment->id)
            ->lockForUpdate()
            ->with(['phases' => fn ($query) => $query->orderBy('sequence')])
            ->get(['id', 'assignment_no', 'status', 'started_at', 'closed_at']);

        foreach ($historical as $past) {
            $interval = $this->resolveHistoricalActualInterval($past->phases);

            if ($interval === null) {
                // Cancelled drafts / records with no actual movement history do not block.
                continue;
            }

            if (! HistoricalAssignmentIntervalOverlap::intervalsOverlap(
                $occurredAt,
                $previousPhaseStart,
                $interval['start'],
                $interval['end'],
            )) {
                continue;
            }

            $hStart = $interval['start']->copy()->timezone($timezone)->toDateString();
            $hEnd = $interval['end'] !== null
                ? $interval['end']->copy()->timezone($timezone)->toDateString()
                : 'open';
            $statusLabel = $past->status === CrewAssignmentStatus::Cancelled
                ? 'cancelled'
                : 'completed';

            throw CrewMovementException::make(
                sprintf(
                    'This arrival date overlaps %s assignment %s (%s – %s). Adjust the arrival date or correct the conflicting assignment first.',
                    $statusLabel,
                    $past->assignment_no,
                    $hStart,
                    $hEnd,
                ),
                'backdated_arrival_assignment_overlap',
            );
        }
    }

    /**
     * The backdated prefix is occupancy that Start Assignment never conflict-checked.
     * Only calendar days strictly before the original phase start are new; the
     * original start date was already reserved when the assignment was started.
     */
    private function assertDoesNotOverlapCrewPlanning(
        CrewAssignment $assignment,
        CarbonInterface $occurredAt,
        CarbonInterface $previousPhaseStart,
    ): void {
        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $prefixStart = $occurredAt->copy()->timezone($timezone)->startOfDay();
        $originalStartDay = $previousPhaseStart->copy()->timezone($timezone)->startOfDay();

        if ($prefixStart->gte($originalStartDay)) {
            return;
        }

        $prefixStartDate = $prefixStart->toDateString();
        $prefixEndDate = $originalStartDay->copy()->subDay()->toDateString();

        $plans = CrewPlanningAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->where('employee_id', $assignment->employee_id)
            ->whereNull('crew_assignment_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'planned_arrival_date',
                'planned_join_date',
                'planned_leave_date',
            ]);

        foreach ($plans as $plan) {
            if (! $this->overlapDetector->overlapsPlannedPlanningAssignment($prefixStartDate, $prefixEndDate, $plan)) {
                continue;
            }

            $planStart = ($plan->planned_arrival_date ?? $plan->planned_join_date)?->toDateString() ?? 'unknown';
            $planEnd = $plan->planned_leave_date?->toDateString() ?? $planStart;

            throw CrewMovementException::make(
                sprintf(
                    'This arrival date overlaps crew planning (%s – %s). Adjust the arrival date or update the planning reservation first.',
                    $planStart,
                    $planEnd,
                ),
                'backdated_arrival_planning_overlap',
            );
        }
    }

    /**
     * Build the authoritative operational interval from phases that recorded actual
     * movement timestamps. Lifecycle started_at / closed_at are ignored here because
     * corrections and backdating can diverge from those assignment-level fields.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{start: CarbonInterface, end: CarbonInterface|null}|null
     */
    private function resolveHistoricalActualInterval(Collection $phases): ?array
    {
        $actualPhases = $phases
            ->filter(fn (CrewAssignmentPhase $phase): bool => $phase->actual_start_at !== null)
            ->values();

        if ($actualPhases->isEmpty()) {
            return null;
        }

        /** @var CarbonInterface $start */
        $start = $actualPhases
            ->map(fn (CrewAssignmentPhase $phase): CarbonInterface => $phase->actual_start_at)
            ->sortBy(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();

        $hasOpenPhase = $actualPhases->contains(
            fn (CrewAssignmentPhase $phase): bool => $phase->actual_end_at === null,
        );

        if ($hasOpenPhase) {
            return [
                'start' => $start,
                'end' => null,
            ];
        }

        /** @var CarbonInterface $end */
        $end = $actualPhases
            ->map(fn (CrewAssignmentPhase $phase): CarbonInterface => $phase->actual_end_at)
            ->sortByDesc(fn (CarbonInterface $timestamp): int => $timestamp->getTimestamp())
            ->first();

        return [
            'start' => $start,
            'end' => $end,
        ];
    }
}
