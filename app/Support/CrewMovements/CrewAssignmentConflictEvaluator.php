<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CrewAssignmentConflictEvaluator
{
    /**
     * Evaluate availability and conflicts for a proposed assignment action.
     */
    public function evaluate(
        CrewAssignmentConflictContext $context,
        bool $withLock = false,
    ): CrewAssignmentConflictResult {
        $timezone = CompanyTimezone::forCompanyId($context->companyId);

        // 1. Tenant & active employee check
        $employeeQuery = Employee::query()
            ->where('company_id', $context->companyId)
            ->whereKey($context->employeeId);

        if ($withLock) {
            $employeeQuery->lockForUpdate();
        }

        $employee = $employeeQuery->first(['id', 'company_id', 'name', 'employee_no', 'status', 'rank_id']);

        if ($employee === null) {
            return CrewAssignmentConflictResult::blocking(
                code: 'tenant_isolation_violation',
                message: 'The selected employee does not belong to this company.',
            );
        }

        if ($employee->status !== 'active') {
            return CrewAssignmentConflictResult::blocking(
                code: 'employee_not_active',
                message: 'Only active employees can receive a crew assignment.',
            );
        }

        // 2. Tenant checks on related master entities
        $vessel = null;
        if ($context->vesselId !== null) {
            $vessel = Vessel::query()
                ->where('company_id', $context->companyId)
                ->whereKey($context->vesselId)
                ->first(['id', 'company_id', 'name', 'client_id']);

            if ($vessel === null) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'tenant_isolation_violation',
                    message: 'The selected vessel does not belong to this company.',
                );
            }
        }

        $client = null;
        if ($context->clientId !== null) {
            $client = Client::query()
                ->whereKey($context->clientId)
                ->where('is_active', true)
                ->first(['id', 'name']);

            if ($client === null) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'invalid_client',
                    message: 'The selected client is invalid or inactive.',
                );
            }
        }

        $rank = null;
        if ($context->rankId !== null) {
            $rank = Rank::query()
                ->whereKey($context->rankId)
                ->where('is_active', true)
                ->first(['id', 'name']);

            if ($rank === null) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'invalid_rank',
                    message: 'The selected rank is invalid or inactive.',
                );
            }
        }

        // 3. Relieved assignment check
        if ($context->relievesCrewAssignmentId !== null) {
            $relievedQuery = CrewAssignment::query()
                ->where('company_id', $context->companyId)
                ->whereKey($context->relievesCrewAssignmentId)
                ->with(['employee:id,name,employee_no,department_id,user_id', 'currentPhase', 'vessel:id,name', 'rank:id,name']);

            if ($withLock) {
                $relievedQuery->lockForUpdate();
            }

            $relieved = $relievedQuery->first();

            if ($relieved === null) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'tenant_isolation_violation',
                    message: 'The assignment being relieved could not be found.',
                );
            }

            $relievedEmployee = $relieved->employee;

            if ($relievedEmployee === null
                || ($context->actor !== null
                    && ! EmployeeVisibilityScope::canAccess($context->actor, $relievedEmployee, $context->companyId))) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'relief_unavailable',
                    message: 'The assignment being relieved could not be found.',
                );
            }

            if ($relieved->status !== CrewAssignmentStatus::Active
                || $relieved->currentPhase?->phase_code !== CrewPhaseCode::OnVessel
                || $relieved->currentPhase?->status !== CrewPhaseStatus::Active) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'relief_not_on_vessel',
                    message: 'Relief can only be planned for an active On Vessel assignment.',
                );
            }

            if ($context->vesselId !== null && (int) $context->vesselId !== (int) $relieved->vessel_id) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'relief_vessel_mismatch',
                    message: 'The relief assignment must be on the same vessel as the assignment being relieved.',
                );
            }

            $sourceRankId = $relieved->rank_id ?? $relieved->employee?->rank_id;
            if ($context->rankId !== null && $sourceRankId !== null && (int) $context->rankId !== (int) $sourceRankId) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'relief_rank_mismatch',
                    message: 'The relief assignment must be for the same rank as the assignment being relieved.',
                );
            }

            // Exclusivity: only one planned relief per active assignment
            $existingReliefQuery = CrewAssignment::query()
                ->where('company_id', $context->companyId)
                ->where('relieves_crew_assignment_id', $context->relievesCrewAssignmentId)
                ->whereIn('status', [CrewAssignmentStatus::Planned, CrewAssignmentStatus::Active])
                ->when($context->currentAssignmentId !== null, fn ($q) => $q->whereKeyNot($context->currentAssignmentId));

            if ($withLock) {
                $existingReliefQuery->lockForUpdate();
            }

            if ($existingReliefQuery->exists()) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'relief_already_planned',
                    message: 'An active operational relief is already assigned to this onboard assignment.',
                );
            }
        }

        // 4. Date order checks
        $arrivalDate = $context->plannedArrivalAt?->copy()->timezone($timezone)->toDateString();
        $joinDate = $context->plannedJoinAt?->copy()->timezone($timezone)->toDateString();
        $signoffDate = $context->plannedSignoffAt?->copy()->timezone($timezone)->toDateString();
        $operationalStartDate = $context->operationalStartAt?->copy()->timezone($timezone)->toDateString();

        if ($arrivalDate !== null && $joinDate !== null && $arrivalDate > $joinDate) {
            return CrewAssignmentConflictResult::blocking(
                code: 'invalid_date_range',
                message: 'Arrival Date cannot be after Expected Vessel Join.',
            );
        }

        if ($joinDate !== null && $signoffDate !== null && $signoffDate < $joinDate) {
            return CrewAssignmentConflictResult::blocking(
                code: 'invalid_date_range',
                message: 'Expected Sign-off cannot be before Expected Vessel Join.',
            );
        }

        // Start only: Expected Sign-Off cannot precede the operational Assignment Start date.
        // Do not invent planned_join_at; do not apply this to plan/draft.
        if (
            $context->action === 'start'
            && $operationalStartDate !== null
            && $signoffDate !== null
            && $signoffDate < $operationalStartDate
        ) {
            return CrewAssignmentConflictResult::blocking(
                code: 'invalid_date_range',
                message: 'Expected Sign-Off cannot be before Assignment Start.',
            );
        }

        // 5. Draft actions do not reserve crew
        if ($context->action === 'draft') {
            return CrewAssignmentConflictResult::none();
        }

        $newAssignmentData = [
            'vessel_id' => $vessel?->id,
            'vessel_name' => $vessel?->name ?? 'Unassigned Vessel',
            'rank_id' => $rank?->id,
            'rank_name' => $rank?->name,
            'planned_join_at' => $joinDate,
            'planned_signoff_at' => $signoffDate,
        ];

        // 6. Action: START
        if ($context->action === 'start') {
            // Hard block if active assignment exists
            $activeQuery = CrewAssignment::query()
                ->where('company_id', $context->companyId)
                ->where('employee_id', $context->employeeId)
                ->where('status', CrewAssignmentStatus::Active)
                ->when($context->currentAssignmentId !== null, fn ($q) => $q->whereKeyNot($context->currentAssignmentId))
                ->with(['currentPhase', 'vessel:id,name', 'rank:id,name']);

            if ($withLock) {
                $activeQuery->lockForUpdate();
            }

            $activeAssignment = $activeQuery->first();

            if ($activeAssignment !== null) {
                $currentPhaseCode = $activeAssignment->currentPhase?->phase_code->value;
                $currentPhaseName = $activeAssignment->currentPhase?->phase_code->label() ?? 'Active';
                $existingVessel = $activeAssignment->vessel?->name ?? 'another vessel';

                $allowedActions = ['reschedule', 'view_current_assignment', 'cancel'];
                if ($activeAssignment->currentPhase?->phase_code === CrewPhaseCode::OnVessel) {
                    $allowedActions[] = 'transfer_vessel';
                }

                return CrewAssignmentConflictResult::blocking(
                    code: 'active_assignment_exists',
                    message: "{$employee->name} currently has an active assignment: {$existingVessel} (Current phase: {$currentPhaseName}). The requested assignment conflicts with the existing operational assignment.",
                    existingAssignment: [
                        'id' => $activeAssignment->id,
                        'assignment_no' => $activeAssignment->assignment_no,
                        'vessel_id' => $activeAssignment->vessel_id,
                        'vessel_name' => $existingVessel,
                        'rank_id' => $activeAssignment->rank_id,
                        'rank_name' => $activeAssignment->rank?->name,
                        'status' => $activeAssignment->status->value,
                        'current_phase_code' => $currentPhaseCode,
                        'current_phase_name' => $currentPhaseName,
                        'start_date' => $activeAssignment->started_at?->copy()->timezone($timezone)->toDateString(),
                        'end_date' => $activeAssignment->planned_signoff_at?->copy()->timezone($timezone)->toDateString(),
                    ],
                    newAssignment: $newAssignmentData,
                    affectedDates: [
                        'start' => $joinDate ?? $operationalStartDate,
                        'end' => $signoffDate,
                    ],
                    allowedActions: $allowedActions,
                );
            }

            // Conflict window starts at operational Start Assignment time.
            // Expected Join remains a forecast only — never invent or substitute it here.
            $conflictStart = $operationalStartDate ?? $joinDate;

            if ($conflictStart !== null) {
                $plannedOverlaps = $this->findPlannedOverlaps(
                    $context,
                    $conflictStart,
                    $signoffDate,
                    $timezone,
                    $withLock,
                );

                if ($plannedOverlaps !== null) {
                    return $plannedOverlaps;
                }

                if ($signoffDate !== null) {
                    $historical = $this->findHistoricalOverlap($context, $conflictStart, $signoffDate, $timezone, $withLock);
                    if ($historical !== null) {
                        return $historical;
                    }
                }
            }

            return CrewAssignmentConflictResult::none();
        }

        // 7. Action: PLAN
        if ($context->action === 'plan') {
            if ($joinDate === null || $signoffDate === null) {
                return CrewAssignmentConflictResult::blocking(
                    code: 'missing_planned_dates',
                    message: 'Expected Vessel Join and Expected Sign-off are required to reserve planned crew availability.',
                );
            }

            $reqStart = $arrivalDate ?? $joinDate;
            $reqEnd = $signoffDate;

            // Check overlap with active assignment
            $activeQuery = CrewAssignment::query()
                ->where('company_id', $context->companyId)
                ->where('employee_id', $context->employeeId)
                ->where('status', CrewAssignmentStatus::Active)
                ->when($context->currentAssignmentId !== null, fn ($q) => $q->whereKeyNot($context->currentAssignmentId))
                ->with(['currentPhase', 'vessel:id,name', 'rank:id,name']);

            if ($withLock) {
                $activeQuery->lockForUpdate();
            }

            $active = $activeQuery->first();

            if ($active !== null) {
                $activeStart = ($active->started_at ?? $active->planned_join_at)?->copy()->timezone($timezone)->toDateString()
                    ?? CarbonImmutable::now($timezone)->toDateString();
                $activeEnd = $active->planned_signoff_at?->copy()->timezone($timezone)->toDateString();

                // If active assignment has no signoff, it is actively ongoing; any plan unconditionally conflicts.
                $activeOverlaps = $activeEnd === null
                    ? true
                    : ($reqStart <= $activeEnd && $activeStart <= $reqEnd);

                if ($activeOverlaps) {
                    $existingVessel = $active->vessel?->name ?? 'another vessel';
                    $currentPhaseName = $active->currentPhase?->phase_code->label() ?? 'Active';

                    $allowedActions = ['reschedule', 'view_current_assignment', 'cancel'];
                    if ($active->currentPhase?->phase_code === CrewPhaseCode::OnVessel) {
                        $allowedActions[] = 'transfer_vessel';
                    }

                    $overlapStart = max($reqStart, $activeStart);
                    $overlapEnd = $activeEnd !== null ? min($reqEnd, $activeEnd) : $reqEnd;

                    return CrewAssignmentConflictResult::blocking(
                        code: 'active_planned_overlap',
                        message: "{$employee->name} currently has an active assignment: {$existingVessel} (Current phase: {$currentPhaseName}). The requested {$newAssignmentData['vessel_name']} assignment conflicts with the existing operational assignment.",
                        existingAssignment: [
                            'id' => $active->id,
                            'assignment_no' => $active->assignment_no,
                            'vessel_id' => $active->vessel_id,
                            'vessel_name' => $existingVessel,
                            'rank_id' => $active->rank_id,
                            'rank_name' => $active->rank?->name,
                            'status' => $active->status->value,
                            'current_phase_code' => $active->currentPhase?->phase_code->value,
                            'current_phase_name' => $currentPhaseName,
                            'start_date' => $activeStart,
                            'end_date' => $activeEnd,
                        ],
                        newAssignment: $newAssignmentData,
                        affectedDates: [
                            'start' => $overlapStart,
                            'end' => $overlapEnd,
                        ],
                        allowedActions: $allowedActions,
                    );
                }
            }

            // Check overlap with other planned assignments
            $plannedOverlaps = $this->findPlannedOverlaps(
                $context,
                $reqStart,
                $reqEnd,
                $timezone,
                $withLock,
            );

            if ($plannedOverlaps !== null) {
                return $plannedOverlaps;
            }

            // Check historical completed assignments
            $historical = $this->findHistoricalOverlap($context, $reqStart, $reqEnd, $timezone, $withLock);
            if ($historical !== null) {
                return $historical;
            }

            return CrewAssignmentConflictResult::none();
        }

        return CrewAssignmentConflictResult::none();
    }

    /**
     * Assert that there are no blocking conflicts; throws ValidationException on conflict.
     *
     * @throws ValidationException
     */
    public function assertNoBlockingConflicts(
        CrewAssignmentConflictContext $context,
        bool $withLock = false,
    ): CrewAssignmentConflictResult {
        $result = $this->evaluate($context, $withLock);

        if ($result->blocking) {
            throw ValidationException::withMessages([
                'employee_id' => $result->message,
                'conflict' => json_encode($result->toArray()),
            ]);
        }

        return $result;
    }

    private function findPlannedOverlaps(
        CrewAssignmentConflictContext $context,
        string $reqStart,
        ?string $reqEnd,
        string $timezone,
        bool $withLock,
    ): ?CrewAssignmentConflictResult {
        $query = CrewAssignment::query()
            ->where('company_id', $context->companyId)
            ->where('employee_id', $context->employeeId)
            ->where('status', CrewAssignmentStatus::Planned)
            ->when($context->currentAssignmentId !== null, fn ($q) => $q->whereKeyNot($context->currentAssignmentId))
            ->with(['vessel:id,name', 'rank:id,name', 'employee:id,name']);

        if ($withLock) {
            $query->lockForUpdate();
        }

        $existingPlans = $query->get();
        $forecastJoin = $context->plannedJoinAt?->copy()->timezone($timezone)->toDateString();
        $forecastSignoff = $context->plannedSignoffAt?->copy()->timezone($timezone)->toDateString();

        foreach ($existingPlans as $plan) {
            $pStart = ($plan->planned_arrival_at ?? $plan->planned_join_at)?->copy()->timezone($timezone)->toDateString();
            $pEnd = $plan->planned_signoff_at?->copy()->timezone($timezone)->toDateString();

            if ($pStart === null) {
                continue;
            }

            $effectivePEnd = $pEnd ?? $pStart;

            if ($reqEnd === null) {
                // Open-ended operational window from $reqStart: overlaps any plan ending on/after that day.
                if ($effectivePEnd < $reqStart) {
                    continue;
                }

                $overlapStart = max($reqStart, $pStart);
                $overlapEnd = $effectivePEnd;
                $reqEndLabel = 'open-ended';
            } else {
                $overlapStart = max($reqStart, $pStart);
                $overlapEnd = min($reqEnd, $effectivePEnd);

                if ($overlapStart > $overlapEnd) {
                    continue;
                }

                $reqEndLabel = $reqEnd;
            }

            $employeeName = $plan->employee?->name ?? 'Employee';
            $existingVessel = $plan->vessel?->name ?? 'Unassigned Vessel';
            $newVessel = $context->vesselId ? (Vessel::find($context->vesselId)?->name ?? 'Selected Vessel') : 'New Assignment';

            $allowedActions = ['adjust_dates', 'edit_existing_plan', 'cancel_existing_plan', 'cancel'];
            if ($context->actor !== null) {
                $allowedActions = ['adjust_dates', 'cancel'];
                if (Gate::forUser($context->actor)->allows('update', $plan)) {
                    $allowedActions[] = 'edit_existing_plan';
                }
                if (Gate::forUser($context->actor)->allows('cancel', $plan)) {
                    $allowedActions[] = 'cancel_existing_plan';
                }
            }

            return CrewAssignmentConflictResult::blocking(
                code: 'planned_planned_overlap',
                message: "{$employeeName} is already planned for: {$existingVessel} ({$pStart} - {$effectivePEnd}). New assignment: {$newVessel} ({$reqStart} - {$reqEndLabel}). These dates overlap from {$overlapStart} to {$overlapEnd}.",
                existingAssignment: [
                    'id' => $plan->id,
                    'assignment_no' => $plan->assignment_no,
                    'vessel_id' => $plan->vessel_id,
                    'vessel_name' => $existingVessel,
                    'rank_id' => $plan->rank_id,
                    'rank_name' => $plan->rank?->name,
                    'status' => $plan->status->value,
                    'start_date' => $pStart,
                    'end_date' => $effectivePEnd,
                ],
                newAssignment: [
                    'vessel_id' => $context->vesselId,
                    'vessel_name' => $newVessel,
                    'rank_id' => $context->rankId,
                    'planned_join_at' => $forecastJoin,
                    'planned_signoff_at' => $forecastSignoff,
                ],
                affectedDates: [
                    'start' => $overlapStart,
                    'end' => $overlapEnd,
                ],
                allowedActions: $allowedActions,
            );
        }

        return null;
    }

    private function findHistoricalOverlap(
        CrewAssignmentConflictContext $context,
        string $reqStart,
        string $reqEnd,
        string $timezone,
        bool $withLock,
    ): ?CrewAssignmentConflictResult {
        $query = CrewAssignment::query()
            ->where('company_id', $context->companyId)
            ->where('employee_id', $context->employeeId)
            ->where('status', CrewAssignmentStatus::Completed)
            ->whereNotNull('started_at')
            ->whereNotNull('closed_at')
            ->when($context->currentAssignmentId !== null, fn ($q) => $q->whereKeyNot($context->currentAssignmentId))
            ->with(['vessel:id,name', 'rank:id,name', 'employee:id,name']);

        if ($withLock) {
            $query->lockForUpdate();
        }

        $completed = $query->get();

        foreach ($completed as $past) {
            $hStart = $past->started_at->copy()->timezone($timezone)->toDateString();
            $hEnd = $past->closed_at->copy()->timezone($timezone)->toDateString();

            if ($reqStart <= $hEnd && $hStart <= $reqEnd) {
                $existingVessel = $past->vessel?->name ?? 'historical vessel';

                return CrewAssignmentConflictResult::blocking(
                    code: 'historical_overlap',
                    message: "Requested dates ({$reqStart} - {$reqEnd}) overlap completed historical assignment {$past->assignment_no} on {$existingVessel} ({$hStart} - {$hEnd}). Historical operational dates cannot be overwritten.",
                    existingAssignment: [
                        'id' => $past->id,
                        'assignment_no' => $past->assignment_no,
                        'vessel_id' => $past->vessel_id,
                        'vessel_name' => $existingVessel,
                        'status' => $past->status->value,
                        'start_date' => $hStart,
                        'end_date' => $hEnd,
                    ],
                    affectedDates: [
                        'start' => max($reqStart, $hStart),
                        'end' => min($reqEnd, $hEnd),
                    ],
                    allowedActions: ['cancel'],
                );
            }
        }

        return null;
    }
}
