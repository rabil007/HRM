<?php

namespace App\Support\CrewOperations;

use App\Models\User;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;

final class CrewReadinessPresenter
{
    /**
     * @param  array{
     *     source_type: 'planning'|'assignment',
     *     planning_assignment_id: int|null,
     *     crew_assignment_id: int|null,
     *     employee: array{id: int, name: string, employee_no: string|null},
     *     vessel: array{id: int, name: string}|null,
     *     position: array{id: int, title: string}|null,
     *     phase_code: string|null,
     *     phase_label: string|null,
     *     expected_arrival_date: string|null,
     *     expected_join_date: string|null,
     *     expected_signoff_date: string|null,
     *     days_until_join: int|null,
     *     is_overdue: bool,
     *     is_joining_soon: bool,
     *     readiness: CrewMobilisationReadinessResult
     * }  $candidate
     * @return array<string, mixed>
     */
    public function row(array $candidate, User $user): array
    {
        $canViewEmployees = $user->can('employees.view');
        $canViewVessels = $user->can('crew_operations.vessels.view');
        $canViewPlanning = $user->can('crew_operations.planning.view');
        $canViewAssignments = $user->can('crew_operations.assignments.view');
        $canStartMobilisation = $canViewPlanning
            && $user->can('crew_operations.assignments.create')
            && $user->can('crew_operations.movements.perform');

        $sourceType = $candidate['source_type'];
        $planningId = $candidate['planning_assignment_id'];
        $assignmentId = $candidate['crew_assignment_id'];
        $vesselId = $candidate['vessel']['id'] ?? null;
        $positionId = $candidate['position']['id'] ?? null;

        $planHref = null;
        if ($canViewPlanning && $planningId !== null) {
            $params = array_filter([
                'vessel_id' => $vesselId,
                'position_id' => $positionId,
                'planning_assignment_id' => $planningId,
            ], fn ($val): bool => $val !== null && $val !== '');

            $planHref = route('organization.crew-planning.index', $params);
        }

        $assignmentHref = null;
        if ($canViewAssignments && $assignmentId !== null) {
            $assignmentHref = route('organization.crew-assignments.show', $assignmentId);
        }

        $startMobilisationHref = null;
        if ($canStartMobilisation && $sourceType === 'planning' && $planningId !== null) {
            $startMobilisationHref = route('organization.crew-planning.assignments.start-mobilisation', $planningId);
        }

        $readiness = $candidate['readiness'];
        $readinessArray = $readiness->toArray(compact: false);
        $readinessArray['has_configured_checks'] = $readiness->hasConfiguredChecks();

        $stageLabel = $sourceType === 'planning'
            ? 'Planned'
            : ($candidate['phase_label'] ?? 'Draft');

        $sourceLabel = $sourceType === 'planning'
            ? 'Future Planning'
            : 'Operational Pre-Join';

        $id = $sourceType === 'planning'
            ? "planning-{$planningId}"
            : "assignment-{$assignmentId}";

        return [
            'id' => $id,
            'source_type' => $sourceType,
            'source_label' => $sourceLabel,
            'stage_label' => $stageLabel,
            'phase_code' => $candidate['phase_code'],
            'employee' => [
                'id' => $candidate['employee']['id'],
                'name' => $candidate['employee']['name'],
                'employee_no' => $candidate['employee']['employee_no'],
                'href' => $canViewEmployees
                    ? route('organization.employees.show', $candidate['employee']['id'])
                    : null,
            ],
            'vessel' => $candidate['vessel'] !== null ? [
                'id' => $candidate['vessel']['id'],
                'name' => $candidate['vessel']['name'],
                'href' => $canViewVessels
                    ? route('organization.vessels.show', $candidate['vessel']['id'])
                    : null,
            ] : null,
            'position' => $candidate['position'] !== null ? [
                'id' => $candidate['position']['id'],
                'title' => $candidate['position']['title'],
            ] : null,
            'expected_arrival_date' => $candidate['expected_arrival_date'],
            'expected_join_date' => $candidate['expected_join_date'],
            'expected_signoff_date' => $candidate['expected_signoff_date'],
            'days_until_join' => $candidate['days_until_join'],
            'is_overdue' => $candidate['is_overdue'],
            'is_joining_soon' => $candidate['is_joining_soon'],
            'readiness' => $readinessArray,
            'planning_assignment_id' => $planningId,
            'crew_assignment_id' => $assignmentId,
            'can_view_plan' => $canViewPlanning && $planningId !== null,
            'plan_href' => $planHref,
            'can_view_assignment' => $canViewAssignments && $assignmentId !== null,
            'assignment_href' => $assignmentHref,
            'can_start_mobilisation' => $startMobilisationHref !== null,
            'start_mobilisation_href' => $startMobilisationHref,
        ];
    }
}
