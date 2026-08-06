<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CrewReliefReadinessResolver
{
    /**
     * Resolve readiness for an onboard source assignment.
     *
     * Pass a preloaded active relief Planning row (and nested relations) to avoid N+1.
     */
    public function forSourceAssignment(
        CrewAssignment $source,
        ?CrewPlanningAssignment $reliefPlan = null,
        ?CarbonInterface $asOf = null,
        ?string $timezone = null,
    ): CrewReliefReadinessResult {
        $timezone ??= $this->resolveTimezone($source);
        $asOf ??= now($timezone);
        $signoffDate = $source->planned_signoff_at !== null
            ? $source->planned_signoff_at->copy()->timezone($timezone)->toDateString()
            : null;
        $daysUntil = $signoffDate !== null
            ? $this->daysUntilSignoff($signoffDate, $timezone, $asOf)
            : null;

        $plan = $reliefPlan ?? $this->findActiveReliefPlan((int) $source->company_id, (int) $source->id);

        if ($plan === null) {
            return CrewReliefReadinessResult::none($signoffDate, $daysUntil);
        }

        $linked = $plan->relationLoaded('crewAssignment')
            ? $plan->crewAssignment
            : $plan->crewAssignment()->with('currentPhase')->first();

        if ($linked !== null && $linked->status === CrewAssignmentStatus::Cancelled) {
            return CrewReliefReadinessResult::none($signoffDate, $daysUntil);
        }

        $status = $this->resolveStatus($plan, $linked);
        $risk = $this->riskFor($status, $daysUntil);
        $phase = $linked?->currentPhase;

        $employee = $plan->employee;

        return new CrewReliefReadinessResult(
            status: $status,
            risk: $risk,
            reliefEmployee: $employee !== null ? [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no !== null ? (string) $employee->employee_no : null,
            ] : null,
            reliefPlanningAssignmentId: (int) $plan->id,
            reliefCrewAssignmentId: $linked?->id !== null ? (int) $linked->id : null,
            reliefPlannedJoinDate: $plan->planned_join_date?->toDateString(),
            reliefPhase: $phase !== null ? [
                'code' => $phase->phase_code->value,
                'label' => $phase->phase_code->label(),
                'status' => $phase->status->value,
            ] : null,
            sourcePlannedSignoffDate: $signoffDate,
            daysUntilSignoff: $daysUntil,
        );
    }

    public function riskFor(CrewReliefStatus $status, ?int $daysUntilSignoff): CrewReliefRisk
    {
        if ($status->isReadyOrOnboard()) {
            return CrewReliefRisk::None;
        }

        if ($daysUntilSignoff === null) {
            return CrewReliefRisk::None;
        }

        if ($daysUntilSignoff <= 0) {
            return CrewReliefRisk::Critical;
        }

        if ($daysUntilSignoff <= 7) {
            return CrewReliefRisk::Critical;
        }

        if ($daysUntilSignoff <= 14) {
            return CrewReliefRisk::Warning;
        }

        return CrewReliefRisk::None;
    }

    public function findActiveReliefPlan(int $companyId, int $sourceAssignmentId): ?CrewPlanningAssignment
    {
        $plans = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('relieves_crew_assignment_id', $sourceAssignmentId)
            ->with([
                'employee:id,name,employee_no',
                'crewAssignment.currentPhase',
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($plans as $plan) {
            if ($this->isOperationallyActive($plan)) {
                return $plan;
            }
        }

        return null;
    }

    public function isOperationallyActive(CrewPlanningAssignment $plan): bool
    {
        if ($plan->trashed()) {
            return false;
        }

        $linked = $plan->relationLoaded('crewAssignment')
            ? $plan->crewAssignment
            : $plan->crewAssignment;

        if ($linked !== null && $linked->status === CrewAssignmentStatus::Cancelled) {
            return false;
        }

        return true;
    }

    private function resolveStatus(
        CrewPlanningAssignment $plan,
        ?CrewAssignment $linked,
    ): CrewReliefStatus {
        if ($linked === null || $plan->crew_assignment_id === null) {
            return CrewReliefStatus::ReliefPlanned;
        }

        $phase = $linked->currentPhase;

        if ($phase === null) {
            return CrewReliefStatus::AssignmentCreated;
        }

        if ($linked->status === CrewAssignmentStatus::Draft
            && $phase->phase_code === CrewPhaseCode::PreMobilisation
            && $phase->status === CrewPhaseStatus::Planned) {
            return CrewReliefStatus::AssignmentCreated;
        }

        if ($phase->phase_code === CrewPhaseCode::OnVessel
            && $phase->status === CrewPhaseStatus::Active
            && $phase->actual_start_at !== null) {
            return CrewReliefStatus::ReliefOnboard;
        }

        if ($phase->phase_code === CrewPhaseCode::ReadyToJoin
            && $phase->status === CrewPhaseStatus::Active) {
            return CrewReliefStatus::ReadyToJoin;
        }

        if (in_array($phase->phase_code, [
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training,
        ], true)) {
            return CrewReliefStatus::Mobilising;
        }

        return CrewReliefStatus::AssignmentCreated;
    }

    public function daysUntilSignoff(
        string $signoffDate,
        string $timezone,
        ?CarbonInterface $asOf = null,
    ): int {
        $signoff = CarbonImmutable::parse($signoffDate, $timezone)->startOfDay();
        $today = CarbonImmutable::parse(
            ($asOf ?? now($timezone))->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();

        return (int) $today->diffInDays($signoff, false);
    }

    private function resolveTimezone(CrewAssignment $assignment): string
    {
        if ($assignment->relationLoaded('company') && $assignment->company !== null) {
            return CompanyTimezone::forCompany($assignment->company);
        }

        return CompanyTimezone::forCompanyId((int) $assignment->company_id);
    }
}
