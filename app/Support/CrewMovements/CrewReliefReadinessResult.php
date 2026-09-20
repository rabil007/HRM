<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

/**
 * @phpstan-type ReliefEmployeeArray array{id: int, name: string, employee_no: string|null}|null
 * @phpstan-type ReliefPhaseArray array{code: string, label: string, status: string}|null
 */
final class CrewReliefReadinessResult
{
    /**
     * @param  ReliefEmployeeArray  $reliefEmployee
     * @param  ReliefPhaseArray  $reliefPhase
     */
    public function __construct(
        public readonly CrewReliefStatus $status,
        public readonly CrewReliefRisk $risk,
        public readonly ?array $reliefEmployee,
        public readonly ?int $reliefPlanningAssignmentId,
        public readonly ?int $reliefCrewAssignmentId,
        public readonly ?string $reliefPlannedJoinDate,
        public readonly ?array $reliefPhase,
        public readonly ?string $sourcePlannedSignoffDate = null,
        public readonly ?int $daysUntilSignoff = null,
    ) {}

    /**
     * @param  list<int>|null  $authorizedReliefEmployeeIds
     *                                                       null with null user = trusted internal context (no redaction);
     *                                                       null with user = unrestricted viewer (no redaction);
     *                                                       array = restricted viewer authorized relief employee IDs.
     */
    public function sanitizeForViewer(?User $user, int $companyId, ?array $authorizedReliefEmployeeIds = null): self
    {
        if ($user === null && $authorizedReliefEmployeeIds === null) {
            return $this;
        }

        if ($this->reliefEmployee === null) {
            return $this;
        }

        $employeeId = (int) ($this->reliefEmployee['id'] ?? 0);
        if ($employeeId <= 0) {
            return $this;
        }

        if ($user !== null
            && $authorizedReliefEmployeeIds === null
            && EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return $this;
        }

        $visible = $authorizedReliefEmployeeIds !== null
            ? in_array($employeeId, $authorizedReliefEmployeeIds, true)
            : ($user !== null && EmployeeVisibilityScope::canAccessId($user, $employeeId, $companyId));

        if ($visible) {
            return $this;
        }

        return new self(
            status: $this->status,
            risk: $this->risk,
            reliefEmployee: null,
            reliefPlanningAssignmentId: null,
            reliefCrewAssignmentId: null,
            reliefPlannedJoinDate: null,
            reliefPhase: null,
            sourcePlannedSignoffDate: $this->sourcePlannedSignoffDate,
            daysUntilSignoff: $this->daysUntilSignoff,
        );
    }

    /**
     * @return array{
     *     relief_status: string,
     *     relief_status_label: string,
     *     relief_action_label: string,
     *     relief_risk: string,
     *     relief_risk_label: string,
     *     relief_employee: array{id: int, name: string, employee_no: string|null}|null,
     *     relief_planning_assignment_id: int|null,
     *     relief_crew_assignment_id: int|null,
     *     relief_planned_join_date: string|null,
     *     relief_phase: array{code: string, label: string, status: string}|null,
     *     relief_phase_code: string|null,
     *     relief_phase_label: string|null,
     *     relief_phase_status: string|null,
     *     source_planned_signoff_date: string|null,
     *     days_until_signoff: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'relief_status' => $this->status->value,
            'relief_status_label' => $this->status->label(),
            'relief_action_label' => $this->status->actionLabel(),
            'relief_risk' => $this->risk->value,
            'relief_risk_label' => $this->risk->label(),
            'relief_employee' => $this->reliefEmployee,
            'relief_planning_assignment_id' => $this->reliefPlanningAssignmentId,
            'relief_crew_assignment_id' => $this->reliefCrewAssignmentId,
            'relief_planned_join_date' => $this->reliefPlannedJoinDate,
            'relief_phase' => $this->reliefPhase,
            'relief_phase_code' => $this->reliefPhase['code'] ?? null,
            'relief_phase_label' => $this->reliefPhase['label'] ?? null,
            'relief_phase_status' => $this->reliefPhase['status'] ?? null,
            'source_planned_signoff_date' => $this->sourcePlannedSignoffDate,
            'days_until_signoff' => $this->daysUntilSignoff,
        ];
    }

    public static function none(?string $sourcePlannedSignoffDate = null, ?int $daysUntilSignoff = null): self
    {
        $status = CrewReliefStatus::NoRelief;
        $risk = (new CrewReliefReadinessResolver)->riskFor($status, $daysUntilSignoff);

        return new self(
            status: $status,
            risk: $risk,
            reliefEmployee: null,
            reliefPlanningAssignmentId: null,
            reliefCrewAssignmentId: null,
            reliefPlannedJoinDate: null,
            reliefPhase: null,
            sourcePlannedSignoffDate: $sourcePlannedSignoffDate,
            daysUntilSignoff: $daysUntilSignoff,
        );
    }
}
