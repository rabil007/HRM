<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\Historical\HistoricalAssignmentIntervalOverlap;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

/**
 * Narrow exception for recording an actual arrival before the initial P0 phase start.
 *
 * Only the first, still-active Pre-Mobilisation phase on an assignment with no later
 * movement history may be reconciled backwards. Assignment created_at and lifecycle
 * started_at are preserved; subsequent phases keep strict chronology.
 */
final class CrewInitialArrivalBackdateGuard
{
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

        $completed = CrewAssignment::query()
            ->where('company_id', $assignment->company_id)
            ->where('employee_id', $assignment->employee_id)
            ->where('status', CrewAssignmentStatus::Completed)
            ->whereNotNull('started_at')
            ->whereNotNull('closed_at')
            ->whereKeyNot($assignment->id)
            ->lockForUpdate()
            ->get(['id', 'assignment_no', 'started_at', 'closed_at', 'vessel_id']);

        foreach ($completed as $past) {
            if (! HistoricalAssignmentIntervalOverlap::intervalsOverlap(
                $occurredAt,
                $previousPhaseStart,
                $past->started_at,
                $past->closed_at,
            )) {
                continue;
            }

            $hStart = $past->started_at->copy()->timezone($timezone)->toDateString();
            $hEnd = $past->closed_at->copy()->timezone($timezone)->toDateString();

            throw CrewMovementException::make(
                sprintf(
                    'This arrival date overlaps completed assignment %s (%s – %s). Adjust the arrival date or correct the conflicting assignment first.',
                    $past->assignment_no,
                    $hStart,
                    $hEnd,
                ),
                'backdated_arrival_assignment_overlap',
            );
        }
    }
}
