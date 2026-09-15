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
     * @param  array{current_stage?: string|null, remarks?: string|null}  $operatorChoices
     * @return array{assignment: CrewAssignment, created_new: bool}
     */
    public function handle(
        CrewPlanningAssignment $planning,
        array $operatorChoices,
        ?int $actorId = null,
    ): array {
        return DB::transaction(function () use ($planning, $operatorChoices, $actorId): array {
            $planning = CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->lockForUpdate()
                ->firstOrFail();

            $companyId = (int) $planning->company_id;

            $linked = $this->handoff->linkedAssignment($planning);

            if ($linked !== null) {
                return [
                    'assignment' => $this->resolveLinkedStart($planning, $linked),
                    'created_new' => false,
                ];
            }

            $masters = $this->handoff->authoritativeStartMasters($planning, $companyId);

            $plannedSignoffAt = $planning->planned_leave_date !== null
                ? $planning->planned_leave_date->toDateString().' 00:00:00'
                : null;

            $assignment = $this->movements->startAssignment(
                $companyId,
                $masters['employee_id'],
                [
                    'rank_id' => $masters['rank_id'],
                    'client_id' => $masters['client_id'],
                    'vessel_id' => $masters['vessel_id'],
                    'planned_join_at' => $masters['planned_join_at'],
                    'current_stage' => $operatorChoices['current_stage'] ?? CrewPhaseCode::TravelIn->value,
                    'remarks' => $operatorChoices['remarks'] ?? null,
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
