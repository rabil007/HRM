<?php

namespace App\Support\Reports\CrewRelief;

use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use App\Support\CrewMovements\CrewReliefRecommendedAction;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Positions\CrewAssignmentPositionPresenter;

final class CrewReliefReportPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>|null  $authorizedReliefEmployeeIds
     * @return array<string, mixed>
     */
    public function present(
        array $row,
        User $user,
        int $companyId,
        ?array $authorizedReliefEmployeeIds = null,
    ): array {
        $assignment = $row['assignment'];
        assert($assignment instanceof CrewAssignment);

        $canViewAssignments = $user->can('crew_operations.assignments.view');
        $canViewEmployees = $user->can('employees.view');
        $canViewVessels = $user->can('crew_operations.vessels.view');

        $employee = $assignment->employee;
        $vessel = $assignment->vessel;
        $client = $assignment->client;
        $position = CrewAssignmentPositionPresenter::option($assignment, $companyId);

        $rawRelief = $row['relief_employee'];
        $visibleReliefEmployee = null;
        $isReliefRestricted = (bool) ($row['is_relief_restricted'] ?? false);

        if (is_array($rawRelief)) {
            $reliefEmpId = (int) $rawRelief['id'];
            $isVisible = $authorizedReliefEmployeeIds !== null
                ? in_array($reliefEmpId, $authorizedReliefEmployeeIds, true)
                : EmployeeVisibilityScope::canAccessId($user, $reliefEmpId, $companyId);

            if ($isVisible) {
                $visibleReliefEmployee = [
                    'id' => $reliefEmpId,
                    'name' => (string) $rawRelief['name'],
                    'employee_no' => $rawRelief['employee_no'],
                    'href' => $canViewEmployees
                        ? route('organization.employees.show', $reliefEmpId)
                        : null,
                ];
            } else {
                $isReliefRestricted = true;
            }
        }

        $nextAssignment = $row['next_assignment'];
        $nextAssignmentPayload = null;

        if (is_array($nextAssignment)) {
            $nextAssignmentPayload = [
                'id' => $nextAssignment['id'],
                'assignment_no' => $nextAssignment['assignment_no'],
                'status' => $nextAssignment['status'],
                'status_label' => $nextAssignment['status_label'],
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.show', $nextAssignment['id'])
                    : null,
            ];
        }

        if ($isReliefRestricted) {
            $reliefStatus = 'Restricted';
            $reliefStatusCode = null;
            $reliefRisk = null;
            $reliefRiskLabel = null;
            $reliefPhaseCode = null;
            $reliefPhaseLabel = null;
            $reliefPlannedJoin = null;
            $readinessCode = 'restricted';
            $readinessLabel = 'Restricted';
            $attentionLevel = $row['attention']['level'];
            $attentionBadge = $row['attention']['badge'];
            $attentionReason = $row['attention']['reason'];
            $reliefPlanningAssignmentId = null;
            $reliefCrewAssignmentId = null;
        } else {
            $reliefStatus = (string) $row['relief_status'];
            $reliefStatusCode = $row['relief_status_code'] ?? null;
            $reliefRisk = $row['relief_risk'] ?? null;
            $reliefRiskLabel = $row['relief_risk_label'] ?? null;
            $reliefPhaseCode = $row['relief_phase_code'] ?? null;
            $reliefPhaseLabel = $row['relief_phase_label'] ?? null;
            $reliefPlannedJoin = $row['relief_planned_join'];
            $readinessCode = $row['readiness']['code'];
            $readinessLabel = $row['readiness']['label'];
            $attentionLevel = $row['attention']['level'];
            $attentionBadge = $row['attention']['badge'];
            $attentionReason = $row['attention']['reason'];
            $reliefPlanningAssignmentId = $canViewAssignments
                ? ($row['relief_planning_assignment_id'] ?? null)
                : null;
            $reliefCrewAssignmentId = $canViewAssignments
                ? ($row['relief_crew_assignment_id'] ?? null)
                : null;
        }

        $reliefResult = $row['relief_result'] ?? null;
        $recommendedAction = null;
        if ($reliefResult instanceof CrewReliefReadinessResult) {
            $recommendedAction = CrewReliefRecommendedAction::for(
                $assignment,
                $reliefResult,
                $user,
                ! $isReliefRestricted,
            );
        }

        return [
            'id' => (int) $assignment->id,
            'assignment_no' => (string) $assignment->assignment_no,
            'source_href' => $canViewAssignments
                ? route('organization.crew-assignments.show', $assignment)
                : null,
            'employee' => $employee !== null ? [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no !== null ? (string) $employee->employee_no : null,
                'photo_url' => $employee->photo_url,
                'href' => $canViewEmployees
                    ? route('organization.employees.show', $employee)
                    : null,
            ] : null,
            'position' => $position,
            'vessel' => $vessel !== null ? [
                'id' => (int) $vessel->id,
                'name' => (string) $vessel->name,
                'href' => $canViewVessels
                    ? route('organization.vessels.show', $vessel)
                    : null,
            ] : null,
            'client' => $client !== null ? [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
            ] : null,
            'joined_date' => $row['actual_joined_date'],
            'days_onboard' => $row['days_onboard'],
            'planned_signoff_at' => $row['planned_signoff_at'],
            'days_to_signoff' => $row['days_until_signoff'],
            'days_to_signoff_label' => $row['days_to_signoff_label'],
            'missing_planned_signoff' => (bool) ($row['missing_planned_signoff'] ?? false),
            'relief_employee' => $visibleReliefEmployee,
            'relief_status' => $reliefStatus,
            'relief_status_code' => $reliefStatusCode,
            'relief_risk' => $reliefRisk,
            'relief_risk_label' => $reliefRiskLabel,
            'relief_phase_code' => $reliefPhaseCode,
            'relief_phase_label' => $reliefPhaseLabel,
            'relief_planned_join' => $reliefPlannedJoin,
            'relief_planning_assignment_id' => $reliefPlanningAssignmentId,
            'relief_crew_assignment_id' => $reliefCrewAssignmentId,
            'readiness' => $readinessCode,
            'readiness_label' => $readinessLabel,
            'next_assignment' => $nextAssignmentPayload,
            'recommended_action' => $recommendedAction,
            'attention' => [
                'level' => $attentionLevel,
                'badge' => $attentionBadge,
                'label' => $attentionBadge,
                'reason' => $attentionReason,
            ],
        ];
    }
}
