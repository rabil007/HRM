<?php

namespace App\Support\Reports\CrewRelief;

use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class CrewReliefReportPresenter
{
    /**
     * @param  array{
     *     assignment: CrewAssignment,
     *     actual_joined_date: string|null,
     *     days_onboard: int|null,
     *     planned_signoff_at: string|null,
     *     days_until_signoff: int|null,
     *     days_to_signoff_label: string|null,
     *     relief_employee: array{id: int, name: string, employee_no: string|null}|null,
     *     relief_status: string,
     *     relief_phase_code: string|null,
     *     relief_planned_join: string|null,
     *     readiness: array{code: string, label: string},
     *     next_assignment: array{id: int, assignment_no: string, status: string, status_label: string}|null,
     *     attention: array{level: string, badge: string, reason: string, urgency_rank: int}
     * }  $row
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
        $canViewAssignments = $user->can('crew_operations.assignments.view');
        $canViewEmployees = $user->can('employees.view');
        $canViewVessels = $user->can('crew_operations.vessels.view');

        $employee = $assignment->employee;
        $vessel = $assignment->vessel;
        $rank = $assignment->rank;
        $client = $assignment->client;

        // Relief employee visibility check
        $rawRelief = $row['relief_employee'];
        $visibleReliefEmployee = null;
        $isReliefRestricted = false;

        if ($rawRelief !== null) {
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

        if ($nextAssignment !== null) {
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
            $reliefPhaseCode = null;
            $reliefPlannedJoin = null;
            $readinessCode = 'restricted';
            $readinessLabel = 'Restricted';
            $attentionLevel = $row['attention']['level'];
            $attentionBadge = $row['attention']['badge'];
            $attentionReason = $row['attention']['reason'];
        } else {
            $reliefStatus = $row['relief_status'];
            $reliefPhaseCode = $row['relief_phase_code'];
            $reliefPlannedJoin = $row['relief_planned_join'];
            $readinessCode = $row['readiness']['code'];
            $readinessLabel = $row['readiness']['label'];
            $attentionLevel = $row['attention']['level'];
            $attentionBadge = $row['attention']['badge'];
            $attentionReason = $row['attention']['reason'];
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
            'rank' => $rank !== null ? [
                'id' => (int) $rank->id,
                'name' => (string) $rank->name,
            ] : null,
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
            'relief_employee' => $visibleReliefEmployee,
            'relief_status' => $reliefStatus,
            'relief_phase_code' => $reliefPhaseCode,
            'relief_planned_join' => $reliefPlannedJoin,
            'readiness' => $readinessCode,
            'readiness_label' => $readinessLabel,
            'next_assignment' => $nextAssignmentPayload,
            'attention' => [
                'level' => $attentionLevel,
                'badge' => $attentionBadge,
                'label' => $attentionBadge,
                'reason' => $attentionReason,
            ],
        ];
    }
}
