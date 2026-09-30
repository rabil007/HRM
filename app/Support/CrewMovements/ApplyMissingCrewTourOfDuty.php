<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Models\CrewAssignment;
use App\Support\Positions\RankPositionBridge;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ApplyMissingCrewTourOfDuty
{
    public function __construct(
        private readonly CrewTourOfDutyResolver $tourOfDutyResolver = new CrewTourOfDutyResolver,

    ) {}

    /**
     * Inspect an assignment for repair eligibility and projected changes without mutating state.
     *
     * @return array{
     *     is_eligible: bool,
     *     tour_of_duty_days: int|null,
     *     actual_join_at: CarbonInterface|null,
     *     existing_planned_signoff_at: CarbonInterface|null,
     *     calculated_planned_signoff_at: CarbonInterface|null,
     *     will_set_planned_signoff: bool,
     *     final_planned_signoff_at: CarbonInterface|null,
     *     timezone: string
     * }|null
     */
    public function inspect(CrewAssignment $assignment): ?array
    {
        if ($assignment->status !== CrewAssignmentStatus::Active) {
            return null;
        }

        $current = $assignment->currentPhase;

        if ($current === null
            || $current->phase_code !== CrewPhaseCode::OnVessel
            || $current->status !== CrewPhaseStatus::Active
            || $current->actual_start_at === null) {
            return null;
        }

        $positionId = RankPositionBridge::resolveCrewAssignmentPositionId(
            (int) $assignment->company_id,
            $assignment->position_id !== null ? (int) $assignment->position_id : null,
            $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
        );

        if ($positionId === null || $assignment->tour_of_duty_days !== null) {
            return null;
        }

        $tour = $this->tourOfDutyResolver->resolve(
            (int) $assignment->company_id,
            $positionId,
            $current->actual_start_at,
        );

        if (! $tour->hasTour()) {
            return null;
        }

        $willSetPlannedSignoff = $assignment->planned_signoff_at === null;

        return [
            'is_eligible' => true,
            'tour_of_duty_days' => $tour->tourOfDutyDays,
            'actual_join_at' => $current->actual_start_at,
            'existing_planned_signoff_at' => $assignment->planned_signoff_at,
            'calculated_planned_signoff_at' => $tour->suggestedPlannedSignoffAt,
            'will_set_planned_signoff' => $willSetPlannedSignoff,
            'final_planned_signoff_at' => $assignment->planned_signoff_at ?? $tour->suggestedPlannedSignoffAt,
            'timezone' => $tour->timezone,
        ];
    }

    /**
     * Apply missing Tour of Duty snapshot to an eligible active P4 assignment.
     */
    public function handle(int $companyId, int $assignmentId, ?int $actorId = null): ?CrewAssignment
    {
        return DB::transaction(function () use ($companyId, $assignmentId, $actorId): ?CrewAssignment {
            $assignment = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->with(['currentPhase', 'phases', 'position', 'rank', 'company', 'employee'])
                ->first();

            if ($assignment === null) {
                return null;
            }

            if ($assignment->status !== CrewAssignmentStatus::Active) {
                return null;
            }

            $current = $assignment->currentPhase;

            if ($current === null
                || $current->phase_code !== CrewPhaseCode::OnVessel
                || $current->status !== CrewPhaseStatus::Active
                || $current->actual_start_at === null) {
                return null;
            }

            $positionId = RankPositionBridge::resolveCrewAssignmentPositionId(
                $companyId,
                $assignment->position_id !== null ? (int) $assignment->position_id : null,
                $assignment->rank_id !== null ? (int) $assignment->rank_id : null,
            );

            if ($positionId === null || $assignment->tour_of_duty_days !== null) {
                return null;
            }

            $tour = $this->tourOfDutyResolver->resolve(
                $companyId,
                $positionId,
                $current->actual_start_at,
            );

            if (! $tour->hasTour()) {
                return null;
            }

            $oldPlannedSignoff = $assignment->planned_signoff_at;
            $needsPlannedSignoff = $assignment->planned_signoff_at === null;

            if ($needsPlannedSignoff) {
                $newPlanned = $tour->suggestedPlannedSignoffAt;

                $assignment->update([
                    'tour_of_duty_days' => $tour->tourOfDutyDays,
                    'planned_signoff_at' => $newPlanned,
                    'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
                    'planned_signoff_override_reason' => null,
                    'updated_by' => $actorId,
                ]);

                $current->update([
                    'planned_end_at' => $newPlanned,
                ]);
            } else {
                $assignment->update([
                    'tour_of_duty_days' => $tour->tourOfDutyDays,
                    'updated_by' => $actorId,
                ]);
            }

            $assignment = $assignment->fresh(['phases', 'employee', 'company', 'position', 'rank', 'currentPhase']) ?? $assignment;

            activity()
                ->performedOn($assignment)
                ->causedBy($actorId)
                ->withProperties([
                    'event' => 'late_tour_of_duty_applied',
                    'company_id' => $assignment->company_id,
                    'assignment_id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'employee_name' => $assignment->employee?->name,
                    'position_id' => $assignment->position_id,
                    'position_name' => $assignment->position?->title,
                    // Temporary Phase 2: retain legacy rank fields for activity readability.
                    'rank_id' => $assignment->rank_id,
                    'rank_name' => $assignment->rank?->name,
                    'old_tour_of_duty_days' => null,
                    'new_tour_of_duty_days' => $tour->tourOfDutyDays,
                    'old_planned_signoff_at' => $oldPlannedSignoff?->toDateTimeString(),
                    'new_planned_signoff_at' => $assignment->planned_signoff_at?->toDateTimeString(),
                    'actual_p4_join' => $current->actual_start_at->toDateTimeString(),
                    'reason' => 'late_tour_of_duty_applied',
                ])
                ->tap(function ($activity) use ($assignment): void {
                    $activity->company_id = $assignment->company_id;
                })
                ->log('Applied missing Tour of Duty to active assignment');

            return $assignment;
        });
    }
}
