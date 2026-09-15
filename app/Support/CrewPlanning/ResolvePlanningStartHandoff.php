<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
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
     *     client_name: string|null,
     *     planned_join_at: string,
     *     remarks: string|null,
     *     current_stage: string
     * }
     */
    public function prefill(CrewPlanningAssignment $planning, int $companyId): array
    {
        $this->assertAuthoritativeForStart($planning, $companyId);

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
            $companyId,
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
            'client_name' => $this->resolveClientName($clientId),
            'planned_join_at' => $planning->planned_join_date->toDateString(),
            'remarks' => $planning->notes,
            'current_stage' => 'p1',
        ];
    }

    /**
     * @return array{
     *     employee_id: int,
     *     rank_id: int,
     *     vessel_id: int,
     *     client_id: int|null,
     *     planned_join_at: string
     * }
     */
    public function authoritativeStartMasters(CrewPlanningAssignment $planning, int $companyId): array
    {
        $this->assertAuthoritativeForStart($planning, $companyId);

        $clientId = ClientAssignmentRules::resolveClientIdFromVessel(
            $companyId,
            (int) $planning->vessel_id,
        );

        return [
            'employee_id' => (int) $planning->employee_id,
            'rank_id' => (int) $planning->rank_id,
            'vessel_id' => (int) $planning->vessel_id,
            'client_id' => $clientId,
            'planned_join_at' => $planning->planned_join_date->toDateString(),
        ];
    }

    public function assertAuthoritativeForStart(CrewPlanningAssignment $planning, int $companyId): void
    {
        if ((int) $planning->company_id !== $companyId) {
            throw CrewMovementException::make(
                'Planning assignment could not be found.',
                'planning_wrong_company',
            );
        }

        $this->assertStartable($planning);
        $this->assertJoinBeforeLeave($planning);
        $this->assertEmployeeIsActive($planning, $companyId);
        $this->assertMastersAreValid($planning, $companyId);
        ValidatesCrewPlanningReliefLink::assertOrThrow($planning);
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

    private function assertJoinBeforeLeave(CrewPlanningAssignment $planning): void
    {
        if ($planning->planned_leave_date === null) {
            return;
        }

        if ($planning->planned_join_date->gt($planning->planned_leave_date)) {
            throw CrewMovementException::make(
                'Expected Vessel Join cannot be after Planned Sign-Off. Update the Crew Planning dates before starting the assignment.',
                'planning_join_after_leave',
            );
        }
    }

    private function assertEmployeeIsActive(CrewPlanningAssignment $planning, int $companyId): void
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $planning->employee_id)
            ->first(['id', 'status']);

        if ($employee === null) {
            throw CrewMovementException::make(
                'The selected employee could not be found in this company.',
                'planning_employee_not_found',
            );
        }

        if ($employee->status !== 'active') {
            throw CrewMovementException::make(
                'The selected employee must be an active employee in this company.',
                'planning_employee_inactive',
            );
        }
    }

    private function assertMastersAreValid(CrewPlanningAssignment $planning, int $companyId): void
    {
        $rankExists = Rank::query()
            ->whereKey((int) $planning->rank_id)
            ->where('is_active', true)
            ->exists();

        if (! $rankExists) {
            throw CrewMovementException::make(
                'The planning rank is no longer active. Update the Crew Planning record before starting.',
                'planning_rank_inactive',
            );
        }

        $vessel = Vessel::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $planning->vessel_id)
            ->first(['id', 'client_id', 'is_active']);

        if ($vessel === null || ! (bool) $vessel->is_active) {
            throw CrewMovementException::make(
                'The planning vessel is no longer active in this company. Update the Crew Planning record before starting.',
                'planning_vessel_inactive',
            );
        }

        $clientId = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vessel->id);

        if ($clientId !== null) {
            $clientValid = Client::query()
                ->whereKey($clientId)
                ->where('is_active', true)
                ->exists();

            if (! $clientValid) {
                throw CrewMovementException::make(
                    'The vessel client is no longer active. Update master data before starting the assignment.',
                    'planning_client_inactive',
                );
            }
        }
    }

    private function resolveClientName(?int $clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        return Client::query()->whereKey($clientId)->value('name');
    }
}
