<?php

namespace App\Support\CrewPlanning;

use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\DB;

final class CreateCrewAssignmentFromPlanning
{
    public function __construct(
        private CrewMovementService $movements,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
    ) {}

    public function handle(CrewPlanningAssignment $planning, ?int $actorId = null): CrewAssignment
    {
        return DB::transaction(function () use ($planning, $actorId): CrewAssignment {
            $planning = CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($planning->crew_assignment_id !== null) {
                $existing = CrewAssignment::query()
                    ->where('company_id', $planning->company_id)
                    ->whereKey($planning->crew_assignment_id)
                    ->first();

                if ($existing !== null) {
                    $this->planningSync->sync($existing);

                    return $existing->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $existing;
                }
            }

            if ($planning->employee_id === null) {
                throw CrewMovementException::make(
                    'Planning assignment has no employee.',
                    'planning_missing_employee',
                );
            }

            if ($planning->vessel_id === null || $planning->rank_id === null) {
                throw CrewMovementException::make(
                    'Planning assignment requires vessel and rank.',
                    'planning_missing_masters',
                );
            }

            if ($planning->planned_join_date === null) {
                throw CrewMovementException::make(
                    'Planning assignment requires a planned join date.',
                    'planning_missing_join_date',
                );
            }

            $assignment = $this->movements->createDraft(
                (int) $planning->company_id,
                (int) $planning->employee_id,
                [
                    'rank_id' => $planning->rank_id,
                    'vessel_id' => $planning->vessel_id,
                    'planned_join_at' => $planning->planned_join_date->toDateString().' 00:00:00',
                    'planned_signoff_at' => $planning->planned_leave_date !== null
                        ? $planning->planned_leave_date->toDateString().' 00:00:00'
                        : null,
                    'source' => 'crew_planning',
                    'remarks' => $planning->notes,
                ],
                $actorId,
            );

            $planning->update([
                'crew_assignment_id' => $assignment->id,
            ]);

            $this->planningSync->sync($assignment->fresh(['phases', 'employee', 'company']) ?? $assignment);

            return $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment;
        });
    }
}
