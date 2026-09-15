<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\MasterData\ClientAssignmentRules;

final class ResolvePlanningStartHandoff
{
    /**
     * @return array{
     *     planning_assignment_id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     rank_id: int,
     *     rank_name: string,
     *     vessel_id: int,
     *     vessel_name: string,
     *     client_id: int|null,
     *     planned_join_at: string,
     *     remarks: string|null,
     *     current_stage: string
     * }
     */
    public function prefill(CrewPlanningAssignment $planning): array
    {
        $this->assertStartable($planning);

        $planning->loadMissing(['employee:id,name', 'rank:id,name', 'vessel:id,name,client_id']);

        $employee = $planning->employee;
        $rank = $planning->rank;
        $vessel = $planning->vessel;

        if ($employee === null || $rank === null || $vessel === null) {
            throw CrewMovementException::make(
                'Planning assignment is missing required employee, rank, or vessel data.',
                'planning_missing_masters',
            );
        }

        $clientId = ClientAssignmentRules::resolveClientIdFromVessel(
            (int) $planning->company_id,
            (int) $vessel->id,
        );

        return [
            'planning_assignment_id' => (int) $planning->id,
            'employee_id' => (int) $employee->id,
            'employee_name' => (string) $employee->name,
            'rank_id' => (int) $rank->id,
            'rank_name' => (string) $rank->name,
            'vessel_id' => (int) $vessel->id,
            'vessel_name' => (string) $vessel->name,
            'client_id' => $clientId,
            'planned_join_at' => $planning->planned_join_date->toDateString(),
            'remarks' => $planning->notes,
            'current_stage' => 'p1',
        ];
    }

    public function linkedAssignment(CrewPlanningAssignment $planning): ?CrewAssignment
    {
        if ($planning->crew_assignment_id === null) {
            return null;
        }

        return CrewAssignment::query()
            ->where('company_id', $planning->company_id)
            ->whereKey($planning->crew_assignment_id)
            ->first();
    }

    public function assertStartable(CrewPlanningAssignment $planning): void
    {
        if ($planning->employee_id === null) {
            throw CrewMovementException::make(
                'Assign a crew member before starting mobilisation from planning.',
                'planning_missing_employee',
            );
        }

        if ($planning->vessel_id === null || $planning->rank_id === null) {
            throw CrewMovementException::make(
                'Planning assignment requires vessel and rank before starting mobilisation.',
                'planning_missing_masters',
            );
        }

        if ($planning->planned_join_date === null) {
            throw CrewMovementException::make(
                'Planning assignment requires a planned join date before starting mobilisation.',
                'planning_missing_join_date',
            );
        }
    }

    public function redirectMessageForLinked(CrewAssignment $assignment): string
    {
        return match ($assignment->status) {
            CrewAssignmentStatus::Active => 'This planning record is already linked to an active crew assignment.',
            CrewAssignmentStatus::Draft => 'This planning record is linked to a draft crew assignment. Continue mobilisation from Crew Assignments.',
            CrewAssignmentStatus::Completed => 'This planning record is linked to a completed crew assignment and cannot be started again.',
            CrewAssignmentStatus::Cancelled => 'This planning record is linked to a cancelled crew assignment and cannot be started again.',
        };
    }
}
