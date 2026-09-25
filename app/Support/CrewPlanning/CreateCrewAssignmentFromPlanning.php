<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\DB;

final class CreateCrewAssignmentFromPlanning
{
    public function __construct(
        private CrewMovementService $movements,
    ) {}

    public function handle(CrewPlanningAssignment $planning, ?int $actorId = null): CrewAssignment
    {
        return DB::transaction(function () use ($planning, $actorId): CrewAssignment {
            $employeeId = $planning->employee_id ?? Employee::factory()->create(['company_id' => $planning->company_id])->id;

            $assignment = $this->movements->createDraft(
                (int) $planning->company_id,
                $employeeId,
                [
                    'rank_id' => $planning->rank_id,
                    'vessel_id' => $planning->vessel_id,
                    'planned_join_at' => $planning->planned_join_date?->toDateString().' 00:00:00',
                    'planned_signoff_at' => $planning->planned_leave_date?->toDateString().' 00:00:00',
                    'relieves_crew_assignment_id' => $planning->relieves_crew_assignment_id,
                    'source' => 'crew_planning',
                    'remarks' => $planning->notes,
                ],
                $actorId,
            );

            $planning->update([
                'crew_assignment_id' => $assignment->id,
            ]);

            return $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment;
        });
    }
}
