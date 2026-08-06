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
     * When no plan is passed, queries for an active operational relief plan.
     * Prefer {@see forPreloadedPlan()} on list/dashboard paths to avoid N+1.
     */
    public function forSourceAssignment(
        CrewAssignment $source,
        ?CrewPlanningAssignment $reliefPlan = null,
        ?CarbonInterface $asOf = null,
        ?string $timezone = null,
    ): CrewReliefReadinessResult {
        if ($reliefPlan !== null) {
            return $this->buildResult($source, $reliefPlan, $asOf, $timezone);
        }

        $timezone ??= $this->resolveTimezone($source);
        $asOf ??= now($timezone);

        $plan = $this->findActiveReliefPlan((int) $source->company_id, (int) $source->id);

        return $this->buildResult($source, $plan, $asOf, $timezone);
    }

    /**
     * Resolve using a batch-preloaded plan (or confirmed absence).
     *
     * Pass null when the loader confirmed there is no active operational plan —
     * this must not trigger another query.
     */
    public function forPreloadedPlan(
        CrewAssignment $source,
        ?CrewPlanningAssignment $reliefPlan,
        ?CarbonInterface $asOf = null,
        ?string $timezone = null,
    ): CrewReliefReadinessResult {
        return $this->buildResult($source, $reliefPlan, $asOf, $timezone);
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

    public function hasActiveOperationalRelief(
        int $companyId,
        int $sourceAssignmentId,
        ?int $exceptPlanningId = null,
    ): bool {
        $plans = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('relieves_crew_assignment_id', $sourceAssignmentId)
            ->when($exceptPlanningId !== null, fn ($q) => $q->whereKeyNot($exceptPlanningId))
            ->with('crewAssignment')
            ->orderByDesc('id')
            ->get();

        foreach ($plans as $plan) {
            if ($this->isOperationallyActive($plan)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active operational relief: non-deleted Planning with no linked assignment,
     * or a linked Draft/Active assignment still in the P0–P4 relief lifecycle.
     */
    public function isOperationallyActive(CrewPlanningAssignment $plan): bool
    {
        if ($plan->trashed()) {
            return false;
        }

        $linked = $plan->relationLoaded('crewAssignment')
            ? $plan->crewAssignment
            : $plan->crewAssignment;

        if ($linked === null) {
            return true;
        }

        return in_array($linked->status, [
            CrewAssignmentStatus::Draft,
            CrewAssignmentStatus::Active,
        ], true);
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

    private function buildResult(
        CrewAssignment $source,
        ?CrewPlanningAssignment $plan,
        ?CarbonInterface $asOf,
        ?string $timezone,
    ): CrewReliefReadinessResult {
        $timezone ??= $this->resolveTimezone($source);
        $asOf ??= now($timezone);
        $signoffDate = $source->planned_signoff_at !== null
            ? $source->planned_signoff_at->copy()->timezone($timezone)->toDateString()
            : null;
        $daysUntil = $signoffDate !== null
            ? $this->daysUntilSignoff($signoffDate, $timezone, $asOf)
            : null;

        if ($plan === null || ! $this->isOperationallyActive($plan)) {
            return CrewReliefReadinessResult::none($signoffDate, $daysUntil);
        }

        $linked = $plan->relationLoaded('crewAssignment')
            ? $plan->crewAssignment
            : $plan->crewAssignment()->with('currentPhase')->first();

        if ($linked !== null && ! in_array($linked->status, [
            CrewAssignmentStatus::Draft,
            CrewAssignmentStatus::Active,
        ], true)) {
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

    private function resolveTimezone(CrewAssignment $assignment): string
    {
        if ($assignment->relationLoaded('company') && $assignment->company !== null) {
            return CompanyTimezone::forCompany($assignment->company);
        }

        return CompanyTimezone::forCompanyId((int) $assignment->company_id);
    }
}
