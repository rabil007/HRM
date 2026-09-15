<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\DB;

final class StartCrewAssignmentFromPlanning
{
    public function __construct(
        private CrewMovementService $movements,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
        private ResolvePlanningStartHandoff $handoff,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{assignment: CrewAssignment, created_new: bool}
     */
    public function handle(
        CrewPlanningAssignment $planning,
        array $attributes,
        ?int $actorId = null,
    ): array {
        return DB::transaction(function () use ($planning, $attributes, $actorId): array {
            $planning = CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->handoff->assertStartable($planning);

            $linked = $this->handoff->linkedAssignment($planning);

            if ($linked !== null) {
                return [
                    'assignment' => $this->resolveLinkedStart($planning, $linked),
                    'created_new' => false,
                ];
            }

            $plannedSignoffAt = $planning->planned_leave_date !== null
                ? $planning->planned_leave_date->toDateString().' 00:00:00'
                : null;

            $assignment = $this->movements->startAssignment(
                (int) $planning->company_id,
                (int) $attributes['employee_id'],
                [
                    'rank_id' => $attributes['rank_id'] ?? null,
                    'client_id' => $attributes['client_id'] ?? null,
                    'vessel_id' => $attributes['vessel_id'] ?? null,
                    'planned_join_at' => $attributes['planned_join_at'] ?? null,
                    'current_stage' => $attributes['current_stage'] ?? CrewPhaseCode::TravelIn->value,
                    'remarks' => $attributes['remarks'] ?? null,
                    'source' => 'crew_planning',
                ],
                $actorId,
            );

            if ($plannedSignoffAt !== null) {
                $assignment->update([
                    'planned_signoff_at' => $plannedSignoffAt,
                    'updated_by' => $actorId,
                ]);
            }

            $planning->update([
                'crew_assignment_id' => $assignment->id,
            ]);

            $this->planningSync->sync($assignment->fresh(['phases', 'employee', 'company']) ?? $assignment);

            return [
                'assignment' => $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment,
                'created_new' => true,
            ];
        });
    }

    private function resolveLinkedStart(
        CrewPlanningAssignment $planning,
        CrewAssignment $linked,
    ): CrewAssignment {
        if ($linked->status === CrewAssignmentStatus::Active) {
            $this->planningSync->sync($linked);

            return $linked->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $linked;
        }

        if ($linked->status === CrewAssignmentStatus::Draft) {
            throw CrewMovementException::make(
                'This planning record is linked to a draft crew assignment. Open the linked assignment and use Start Travel or update the draft before starting again.',
                'planning_linked_draft',
            );
        }

        throw CrewMovementException::make(
            sprintf(
                'This planning record is linked to a %s crew assignment and cannot be started again.',
                strtolower($linked->status->value),
            ),
            'planning_linked_terminal_assignment',
        );
    }
}
