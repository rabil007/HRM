<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;

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
