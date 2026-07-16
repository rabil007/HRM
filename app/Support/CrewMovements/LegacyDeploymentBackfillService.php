<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Converts EmployeeDeployment rows into CrewAssignment / CrewAssignmentPhase.
 * Does not modify deployments. Dual-write is not implemented in this phase.
 */
final class LegacyDeploymentBackfillService
{
    public function __construct(
        private CrewAssignmentNumberGenerator $numbers,
        private CrewAssignmentInvariantGuard $invariants,
    ) {}

    /**
     * @return array{
     *     result: string,
     *     deployment_id: int,
     *     company_id: int,
     *     employee_id: int|null,
     *     assignment_id: int|null,
     *     assignment_no: string|null,
     *     phases: list<array{phase_code: string, sequence: int, status: string}>,
     *     reason: string|null,
     * }
     */
    public function process(EmployeeDeployment $deployment, bool $commit): array
    {
        $base = [
            'deployment_id' => $deployment->id,
            'company_id' => (int) $deployment->company_id,
            'employee_id' => $deployment->employee_id !== null ? (int) $deployment->employee_id : null,
            'assignment_id' => null,
            'assignment_no' => null,
            'phases' => [],
            'reason' => null,
        ];

        $conflict = $this->detectConflict($deployment);
        if ($conflict !== null) {
            return [...$base, 'result' => $conflict['result'], 'reason' => $conflict['reason']];
        }

        $existing = CrewAssignment::query()
            ->where('employee_deployment_id', $deployment->id)
            ->first();

        if ($existing !== null) {
            return [
                ...$base,
                'result' => 'already_migrated',
                'assignment_id' => $existing->id,
                'assignment_no' => $existing->assignment_no,
                'phases' => $existing->phases()->ordered()->get()->map(fn (CrewAssignmentPhase $phase) => [
                    'phase_code' => $phase->phase_code->value,
                    'sequence' => $phase->sequence,
                    'status' => $phase->status->value,
                ])->all(),
            ];
        }

        $built = $this->buildPayload($deployment);
        if (isset($built['conflict'])) {
            return [...$base, 'result' => 'conflict', 'reason' => $built['conflict']];
        }

        if (! $commit) {
            return [
                ...$base,
                'result' => 'eligible',
                'assignment_no' => $built['assignment']['assignment_no'],
                'phases' => collect($built['phases'])->map(fn (array $phase) => [
                    'phase_code' => $phase['phase_code']->value,
                    'sequence' => $phase['sequence'],
                    'status' => $phase['status']->value,
                ])->all(),
            ];
        }

        return DB::transaction(function () use ($deployment, $built, $base): array {
            $lockedExisting = CrewAssignment::query()
                ->where('employee_deployment_id', $deployment->id)
                ->lockForUpdate()
                ->first();

            if ($lockedExisting !== null) {
                return [
                    ...$base,
                    'result' => 'already_migrated',
                    'assignment_id' => $lockedExisting->id,
                    'assignment_no' => $lockedExisting->assignment_no,
                ];
            }

            $assignment = CrewAssignment::query()->create($built['assignment']);

            $createdPhases = [];
            foreach ($built['phases'] as $phaseData) {
                $createdPhases[] = CrewAssignmentPhase::query()->create([
                    ...$phaseData,
                    'company_id' => $assignment->company_id,
                    'crew_assignment_id' => $assignment->id,
                ]);
            }

            $currentPhaseId = $this->resolveCurrentPhaseId($createdPhases);
            $assignment->update(['current_phase_id' => $currentPhaseId]);

            $assignment->refresh()->load(['employee', 'phases', 'currentPhase', 'employeeDeployment', 'crewPlanningAssignment']);
            $this->invariants->assertValid($assignment);

            return [
                ...$base,
                'result' => 'created',
                'assignment_id' => $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'phases' => collect($createdPhases)->map(fn (CrewAssignmentPhase $phase) => [
                    'phase_code' => $phase->phase_code->value,
                    'sequence' => $phase->sequence,
                    'status' => $phase->status->value,
                ])->all(),
            ];
        });
    }

    /**
     * @return array{result: string, reason: string}|null
     */
    private function detectConflict(EmployeeDeployment $deployment): ?array
    {
        if ($deployment->employee_id === null) {
            return ['result' => 'conflict', 'reason' => 'Deployment has no employee.'];
        }

        $employee = $deployment->employee;
        if ($employee === null) {
            return ['result' => 'conflict', 'reason' => 'Employee record is missing.'];
        }

        if ((int) $employee->company_id !== (int) $deployment->company_id) {
            return ['result' => 'conflict', 'reason' => 'Employee company does not match deployment company.'];
        }

        $duplicateLinks = CrewAssignment::query()
            ->where('employee_deployment_id', $deployment->id)
            ->count();

        if ($duplicateLinks > 1) {
            return ['result' => 'conflict', 'reason' => 'Multiple assignments already link to this deployment.'];
        }

        $planning = CrewPlanningAssignment::query()
            ->where('employee_deployment_id', $deployment->id)
            ->first();

        if ($planning !== null) {
            if ((int) $planning->company_id !== (int) $deployment->company_id) {
                return ['result' => 'conflict', 'reason' => 'Linked planning assignment company mismatch.'];
            }
            if ($planning->employee_id !== null
                && (int) $planning->employee_id !== (int) $deployment->employee_id) {
                return ['result' => 'conflict', 'reason' => 'Linked planning assignment employee mismatch.'];
            }
        }

        return null;
    }

    /**
     * @return array{conflict: string}|array{assignment: array<string, mixed>, phases: list<array<string, mixed>>}
     */
    private function buildPayload(EmployeeDeployment $deployment): array
    {
        $timezone = $this->companyTimezone((int) $deployment->company_id);
        $today = now($timezone)->startOfDay();

        $joined = $this->dateStart($deployment->joined_date?->toDateString(), $timezone);
        $disembarked = $this->dateStart($deployment->disembarked_date?->toDateString(), $timezone);
        $arrived = $this->dateStart($deployment->arrived_date?->toDateString(), $timezone);
        $travelled = $this->dateStart($deployment->travelled_date?->toDateString(), $timezone);
        $joinStandbyFrom = $this->dateStart($deployment->join_standby_from?->toDateString(), $timezone);
        $joinStandbyTo = $this->dateStart($deployment->join_standby_to?->toDateString(), $timezone);
        $leaveStandbyFrom = $this->dateStart($deployment->leave_standby_from?->toDateString(), $timezone);
        $leaveStandbyTo = $this->dateStart($deployment->leave_standby_to?->toDateString(), $timezone);

        if ($joined && $disembarked && $disembarked->lt($joined)) {
            return ['conflict' => 'Joined date occurs after actual disembarkation.'];
        }

        $disembarkActual = $disembarked && $disembarked->lte($today) ? $disembarked : null;
        $disembarkPlanned = $disembarked && $disembarked->gt($today) ? $disembarked : null;

        if ($leaveStandbyFrom && $disembarkActual && $leaveStandbyFrom->lt($disembarkActual)) {
            return ['conflict' => 'Leave standby begins before actual disembarkation.'];
        }

        if ($travelled && $disembarkActual && $travelled->lt($disembarkActual)) {
            return ['conflict' => 'Travel occurs before actual disembarkation.'];
        }

        if ($joinStandbyFrom && $joinStandbyTo && $joinStandbyTo->lt($joinStandbyFrom)) {
            return ['conflict' => 'Join standby end is before start.'];
        }

        if ($leaveStandbyFrom && $leaveStandbyTo && $leaveStandbyTo->lt($leaveStandbyFrom)) {
            return ['conflict' => 'Leave standby end is before start.'];
        }

        $phases = [];
        $sequence = 1;

        if ($arrived !== null) {
            $phases[] = $this->phaseRow(
                CrewPhaseCode::TravelIn,
                $sequence++,
                $this->inferCompletedOrActive($arrived, $joinStandbyFrom ?? $joined, $today),
                $arrived,
                $joinStandbyFrom ?? $joined,
            );
        }

        if ($joinStandbyFrom !== null) {
            $phases[] = $this->phaseRow(
                CrewPhaseCode::JoinStandby,
                $sequence++,
                $this->inferStandbyStatus($joinStandbyFrom, $joinStandbyTo, $joined, $today),
                $joinStandbyFrom,
                $joinStandbyTo ?? $joined,
            );
        }

        if ($joined !== null) {
            $p4Status = $disembarkActual === null
                ? ($joined->lte($today) ? CrewPhaseStatus::Active : CrewPhaseStatus::Planned)
                : CrewPhaseStatus::Completed;

            $phases[] = [
                'phase_code' => CrewPhaseCode::OnVessel,
                'sequence' => $sequence++,
                'status' => $p4Status,
                'planned_start_at' => null,
                'planned_end_at' => $disembarkPlanned,
                'actual_start_at' => $joined->lte($today) ? $joined : null,
                'actual_end_at' => $disembarkActual,
                'details' => null,
                'remarks' => null,
            ];
        }

        if ($leaveStandbyFrom !== null) {
            $phases[] = $this->phaseRow(
                CrewPhaseCode::DemobStandby,
                $sequence++,
                $this->inferStandbyStatus($leaveStandbyFrom, $leaveStandbyTo, $travelled, $today),
                $leaveStandbyFrom,
                $leaveStandbyTo ?? $travelled,
            );
        }

        if ($travelled !== null) {
            $phases[] = $this->phaseRow(
                CrewPhaseCode::HomeRedeploy,
                $sequence++,
                $travelled->lte($today) ? CrewPhaseStatus::Completed : CrewPhaseStatus::Planned,
                $travelled->lte($today) ? $travelled : null,
                $travelled->lte($today) ? $travelled : null,
                plannedStart: $travelled->gt($today) ? $travelled : null,
            );
        }

        if ($phases === []) {
            return ['conflict' => 'Deployment has no inferable movement phases.'];
        }

        $activeCount = collect($phases)->where('status', CrewPhaseStatus::Active)->count();
        if ($activeCount > 1) {
            return ['conflict' => 'Inferred more than one active phase.'];
        }

        $hasActive = $activeCount === 1;
        $hasCompletedP6 = collect($phases)->contains(
            fn (array $phase) => $phase['phase_code'] === CrewPhaseCode::HomeRedeploy
                && $phase['status'] === CrewPhaseStatus::Completed,
        );
        $onlyPlanned = collect($phases)->every(
            fn (array $phase) => $phase['status'] === CrewPhaseStatus::Planned,
        );
        $allCompleted = collect($phases)->every(
            fn (array $phase) => $phase['status'] === CrewPhaseStatus::Completed,
        );

        if ($hasActive) {
            $status = CrewAssignmentStatus::Active;
            $startedAt = collect($phases)
                ->pluck('actual_start_at')
                ->filter()
                ->sort()
                ->first() ?? $today;
            $closedAt = null;
        } elseif ($hasCompletedP6 || $allCompleted) {
            $status = CrewAssignmentStatus::Completed;
            $startedAt = collect($phases)->pluck('actual_start_at')->filter()->sort()->first();
            $closedAt = collect($phases)
                ->where('phase_code', CrewPhaseCode::HomeRedeploy)
                ->pluck('actual_end_at')
                ->filter()
                ->first()
                ?? collect($phases)->pluck('actual_end_at')->filter()->sort()->last()
                ?? $travelled
                ?? $disembarkActual
                ?? $today;
        } elseif ($onlyPlanned) {
            $status = CrewAssignmentStatus::Draft;
            $startedAt = null;
            $closedAt = null;
        } else {
            return ['conflict' => 'Deployment phase statuses cannot be mapped to a single assignment status.'];
        }

        if ($status === CrewAssignmentStatus::Active) {
            $conflictActive = CrewAssignment::query()
                ->where('company_id', $deployment->company_id)
                ->where('employee_id', $deployment->employee_id)
                ->where('status', CrewAssignmentStatus::Active)
                ->exists();

            if ($conflictActive) {
                return ['conflict' => 'Employee already has an active crew assignment.'];
            }
        }

        $planningId = CrewPlanningAssignment::query()
            ->where('employee_deployment_id', $deployment->id)
            ->value('id');

        return [
            'assignment' => [
                'company_id' => $deployment->company_id,
                'assignment_no' => $this->numbers->legacyForDeployment((int) $deployment->id),
                'employee_id' => $deployment->employee_id,
                'rank_id' => $deployment->rank_id,
                'client_id' => $deployment->client_id,
                'vessel_id' => $deployment->vessel_id,
                'company_visa_type_id' => $deployment->company_visa_type_id,
                'status' => $status,
                'planned_join_at' => $joined && $joined->gt($today) ? $joined : null,
                'planned_signoff_at' => $disembarkPlanned,
                'planned_travel_at' => $travelled && $travelled->gt($today) ? $travelled : null,
                'started_at' => $startedAt,
                'closed_at' => $closedAt,
                'employee_deployment_id' => $deployment->id,
                'crew_planning_assignment_id' => $planningId,
                'source' => 'legacy_deployment',
                'remarks' => $deployment->remarks,
            ],
            'phases' => $phases,
        ];
    }

    /**
     * @param  list<CrewAssignmentPhase>  $phases
     */
    private function resolveCurrentPhaseId(array $phases): ?int
    {
        $collection = collect($phases);

        $active = $collection->first(fn (CrewAssignmentPhase $phase) => $phase->status === CrewPhaseStatus::Active);
        if ($active !== null) {
            return $active->id;
        }

        $completed = $collection
            ->filter(fn (CrewAssignmentPhase $phase) => $phase->status === CrewPhaseStatus::Completed)
            ->sortByDesc('sequence')
            ->first();
        if ($completed !== null) {
            return $completed->id;
        }

        $planned = $collection
            ->filter(fn (CrewAssignmentPhase $phase) => $phase->status === CrewPhaseStatus::Planned)
            ->sortBy('sequence')
            ->first();

        return $planned?->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseRow(
        CrewPhaseCode $code,
        int $sequence,
        CrewPhaseStatus $status,
        ?CarbonInterface $actualStart,
        ?CarbonInterface $actualEnd,
        ?CarbonInterface $plannedStart = null,
        ?CarbonInterface $plannedEnd = null,
    ): array {
        return [
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => $status,
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'actual_start_at' => $status === CrewPhaseStatus::Planned ? null : $actualStart,
            'actual_end_at' => $status === CrewPhaseStatus::Completed ? $actualEnd : null,
            'details' => null,
            'remarks' => null,
        ];
    }

    private function inferCompletedOrActive(
        CarbonInterface $start,
        ?CarbonInterface $endHint,
        CarbonInterface $today,
    ): CrewPhaseStatus {
        if ($start->gt($today)) {
            return CrewPhaseStatus::Planned;
        }

        if ($endHint !== null && $endHint->lte($today)) {
            return CrewPhaseStatus::Completed;
        }

        return CrewPhaseStatus::Active;
    }

    private function inferStandbyStatus(
        CarbonInterface $from,
        ?CarbonInterface $to,
        ?CarbonInterface $nextHint,
        CarbonInterface $today,
    ): CrewPhaseStatus {
        if ($from->gt($today)) {
            return CrewPhaseStatus::Planned;
        }

        $end = $to ?? $nextHint;
        if ($end !== null && $end->lte($today)) {
            return CrewPhaseStatus::Completed;
        }

        return CrewPhaseStatus::Active;
    }

    private function dateStart(?string $date, string $timezone): ?CarbonInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse($date, $timezone)->startOfDay();
    }

    private function companyTimezone(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));
    }
}
