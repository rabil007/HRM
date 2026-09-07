<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use App\Support\CrewMovements\CrewTourProgress;
use App\Support\Settings\CompanyTimezone;

final class CrewReliefDeskPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function row(
        CrewAssignment $source,
        CrewReliefReadinessResult $relief,
        ?CrewMobilisationReadinessResult $mobilisationReadiness,
        User $user,
        int $companyId,
    ): array {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $tour = (new CrewTourProgress)->forAssignment($source, null, $timezone);
        $employee = $this->tenantEmployee($source, $companyId);
        $vessel = $this->tenantVessel($source, $companyId);
        $rank = $source->rank;
        $canViewAssignments = $user->can('crew_operations.assignments.view');
        $canCreatePlanning = $user->can('crew_operations.planning.create');
        $canViewEmployees = $user->can('employees.view');
        $canViewVessels = $user->can('crew_operations.vessels.view');
        $action = $this->recommendedAction(
            $source,
            $relief,
            $canViewAssignments,
            $canCreatePlanning,
        );

        $readinessApplies = $mobilisationReadiness !== null && $mobilisationReadiness->applies;
        $readinessPayload = $readinessApplies
            ? $mobilisationReadiness->toArray(compact: true)
            : null;

        return [
            'id' => (int) $source->id,
            'assignment_no' => (string) $source->assignment_no,
            'source_href' => $canViewAssignments
                ? route('organization.crew-assignments.show', $source)
                : null,
            'employee' => $employee !== null ? [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no !== null ? (string) $employee->employee_no : null,
                'href' => $canViewEmployees
                    ? route('organization.employees.show', $employee)
                    : null,
            ] : null,
            'vessel' => $vessel !== null ? [
                'id' => (int) $vessel->id,
                'name' => (string) $vessel->name,
                'href' => $canViewVessels
                    ? route('organization.vessels.show', $vessel)
                    : null,
            ] : null,
            'rank' => $rank !== null ? [
                'id' => (int) $rank->id,
                'name' => (string) $rank->name,
            ] : null,
            'current_phase_code' => $source->currentPhase?->phase_code->value,
            'current_phase_label' => $source->currentPhase?->phase_code->label(),
            'current_duty_day' => $tour['current_duty_day'],
            'days_onboard' => $tour['days_onboard'],
            'planned_signoff_at' => $relief->sourcePlannedSignoffDate,
            'days_until_signoff' => $relief->daysUntilSignoff,
            'missing_planned_signoff' => $relief->sourcePlannedSignoffDate === null,
            'relief_status' => $relief->status->value,
            'relief_status_label' => $relief->status->label(),
            'relief_risk' => $relief->risk->value,
            'relief_risk_label' => $this->deskRiskLabel($relief->risk),
            'relief_employee' => $this->reliefEmployeePayload($relief, $canViewEmployees),
            'relief_planning_assignment_id' => $relief->reliefPlanningAssignmentId,
            'relief_crew_assignment_id' => $canViewAssignments
                ? $relief->reliefCrewAssignmentId
                : null,
            'relief_phase_code' => $relief->reliefPhase['code'] ?? null,
            'relief_phase_label' => $relief->reliefPhase['label'] ?? null,
            'relief_planned_join_date' => $relief->reliefPlannedJoinDate,
            'mobilisation_readiness' => $readinessPayload,
            'recommended_action' => $action,
        ];
    }

    /**
     * @return array{key: string, label: string, href: string|null}
     */
    private function recommendedAction(
        CrewAssignment $source,
        CrewReliefReadinessResult $relief,
        bool $canViewAssignments,
        bool $canCreatePlanning,
    ): array {
        return match ($relief->status) {
            CrewReliefStatus::NoRelief => [
                'key' => 'plan_relief',
                'label' => 'Plan Relief',
                'href' => $canCreatePlanning ? $this->planReliefHref($source) : null,
            ],
            CrewReliefStatus::ReliefPlanned => [
                'key' => 'open_relief_plan',
                'label' => 'Open Relief Plan',
                'href' => $this->openReliefPlanHref($source, $relief),
            ],
            CrewReliefStatus::AssignmentCreated,
            CrewReliefStatus::Mobilising => [
                'key' => 'open_relief_assignment',
                'label' => 'Open Relief Assignment',
                'href' => $canViewAssignments && $relief->reliefCrewAssignmentId !== null
                    ? route('organization.crew-assignments.show', $relief->reliefCrewAssignmentId)
                    : null,
            ],
            CrewReliefStatus::ReadyToJoin => [
                'key' => 'open_assignment',
                'label' => 'Open Assignment',
                'href' => $canViewAssignments && $relief->reliefCrewAssignmentId !== null
                    ? route('organization.crew-assignments.show', $relief->reliefCrewAssignmentId)
                    : null,
            ],
            CrewReliefStatus::ReliefOnboard => [
                'key' => 'review_source',
                'label' => 'Review Source Assignment',
                'href' => $canViewAssignments
                    ? route('organization.crew-assignments.show', $source)
                    : null,
            ],
        };
    }

    private function planReliefHref(CrewAssignment $source): string
    {
        return route('organization.crew-planning.index', array_filter([
            'vessel_id' => $source->vessel_id,
            'rank_id' => $source->rank_id,
            'relieves_crew_assignment_id' => $source->id,
            'planned_join_date' => $source->planned_signoff_at?->toDateString(),
            'open_create' => 1,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function openReliefPlanHref(CrewAssignment $source, CrewReliefReadinessResult $relief): string
    {
        return route('organization.crew-planning.index', array_filter([
            'vessel_id' => $source->vessel_id,
            'rank_id' => $source->rank_id,
            'search' => $relief->reliefEmployee['name'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array{id: int, name: string, employee_no: string|null, href: string|null}|null
     */
    private function reliefEmployeePayload(CrewReliefReadinessResult $relief, bool $canViewEmployees): ?array
    {
        if ($relief->reliefEmployee === null) {
            return null;
        }

        return [
            ...$relief->reliefEmployee,
            'href' => $canViewEmployees
                ? route('organization.employees.show', $relief->reliefEmployee['id'])
                : null,
        ];
    }

    private function deskRiskLabel(CrewReliefRisk $risk): string
    {
        return match ($risk) {
            CrewReliefRisk::None => 'Good',
            CrewReliefRisk::Warning => 'Warning',
            CrewReliefRisk::Critical => 'Critical',
        };
    }

    private function tenantEmployee(CrewAssignment $source, int $companyId): mixed
    {
        $employee = $source->employee;

        if ($employee === null || (int) $employee->company_id !== $companyId) {
            return null;
        }

        return $employee;
    }

    private function tenantVessel(CrewAssignment $source, int $companyId): mixed
    {
        $vessel = $source->vessel;

        if ($vessel === null) {
            return null;
        }

        if (isset($vessel->company_id) && (int) $vessel->company_id !== $companyId) {
            return null;
        }

        return $vessel;
    }
}
