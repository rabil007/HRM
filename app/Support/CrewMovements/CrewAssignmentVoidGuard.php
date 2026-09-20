<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeSeaService;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Downstream safety checks for privileged Void Erroneous Assignment.
 *
 * Permission alone is never sufficient; blockers are machine-readable codes.
 */
final class CrewAssignmentVoidGuard
{
    public const BLOCKED_MESSAGE = 'This assignment cannot be voided because it has already affected protected payroll, sea service, or a linked assignment. Use the appropriate correction or reversal workflow instead.';

    public const ACCOMMODATION_BLOCKED_MESSAGE = 'This assignment cannot be voided because accommodation history exists. Use the appropriate correction workflow instead.';

    public const SEA_SERVICE_BLOCKED_MESSAGE = 'This assignment has generated Sea Service records. To delete this erroneous assignment, also select "Delete generated Sea Service", or use the appropriate correction/reversal workflow.';

    /**
     * @return list<array{code: string, message: string}>
     */
    public function blockers(CrewAssignment $assignment, int $companyId, bool $ignoreLinkedSeaService = false): array
    {
        return $this->batchBlockers([$assignment], $companyId, $ignoreLinkedSeaService)[(int) $assignment->id] ?? [];
    }

    public function assertCanVoid(CrewAssignment $assignment, int $companyId, bool $ignoreLinkedSeaService = false): void
    {
        $this->assertCanVoidMany([$assignment], $companyId, $ignoreLinkedSeaService);
    }

    /**
     * Compute blockers for multiple assignments using grouped batch queries to eliminate N+1 overhead.
     *
     * @param  iterable<CrewAssignment>  $assignments
     * @return array<int, list<array{code: string, message: string}>>
     */
    public function batchBlockers(iterable $assignments, int $companyId, bool $ignoreLinkedSeaService = false): array
    {
        $assignmentList = is_array($assignments) ? $assignments : iterator_to_array($assignments);

        if ($assignmentList === []) {
            return [];
        }

        $assignmentIds = [];
        foreach ($assignmentList as $assignment) {
            $assignmentIds[] = (int) $assignment->id;
        }
        $assignmentIds = array_values(array_unique($assignmentIds));

        // 1. Linked child assignments
        $linkedChildAssignmentIds = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('previous_assignment_id', $assignmentIds)
            ->pluck('previous_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 2. Phases & Sea Service
        $phases = CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->get(['id', 'crew_assignment_id']);

        $phaseToAssignment = [];
        $phaseIds = [];
        foreach ($phases as $phase) {
            $pId = (int) $phase->id;
            $phaseIds[] = $pId;
            $phaseToAssignment[$pId] = (int) $phase->crew_assignment_id;
        }

        $assignmentsWithSeaService = [];
        if (! $ignoreLinkedSeaService && $phaseIds !== []) {
            $seaServicePhases = EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->whereIn('crew_assignment_phase_id', $phaseIds)
                ->pluck('crew_assignment_phase_id')
                ->all();

            foreach ($seaServicePhases as $phaseId) {
                $aId = $phaseToAssignment[(int) $phaseId] ?? null;
                if ($aId !== null) {
                    $assignmentsWithSeaService[$aId] = true;
                }
            }
        }

        // 3. Applied payroll preparation lines
        $assignmentsWithAppliedPayroll = CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_lines.company_id', $companyId)
            ->whereIn('crew_timesheet_preparation_lines.crew_assignment_id', $assignmentIds)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->where('status', CrewTimesheetPreparationStatus::Applied);
            })
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 4. Protected payroll: submitted/approved preparation lines
        $assignmentsWithProtectedPrep = CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_lines.company_id', $companyId)
            ->whereIn('crew_timesheet_preparation_lines.crew_assignment_id', $assignmentIds)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        CrewTimesheetPreparationStatus::Submitted,
                        CrewTimesheetPreparationStatus::Approved,
                    ]);
            })
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 5. Protected payroll: timesheet segments on approved/paid/processing periods
        $assignmentsWithProtectedPeriodSegments = CrewTimesheetSegment::query()
            ->where('crew_timesheet_segments.company_id', $companyId)
            ->whereIn('crew_timesheet_segments.crew_assignment_id', $assignmentIds)
            ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        PayrollPeriodStatus::Approved,
                        PayrollPeriodStatus::Paid,
                        PayrollPeriodStatus::Processing,
                    ]);
            })
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 6. Protected payroll: work allocations
        $assignmentsWithWorkAllocations = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved,
                PayrollWorkAllocationStatus::Paid,
                PayrollWorkAllocationStatus::Reserved,
            ])
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 7. Protected timesheet dependency (any segment exists)
        $assignmentsWithSegments = CrewTimesheetSegment::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // 8. Accommodation history
        $assignmentsWithAccommodation = CrewAccommodationStay::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_id', $assignmentIds)
            ->pluck('crew_assignment_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        $result = [];
        foreach ($assignmentList as $assignment) {
            $id = (int) $assignment->id;
            $blockers = [];

            if ((int) $assignment->company_id !== $companyId) {
                $blockers[] = [
                    'code' => 'cross_company',
                    'message' => 'Assignment does not belong to the active company.',
                ];
            }

            if ($assignment->voided_at !== null || $assignment->trashed()) {
                $blockers[] = [
                    'code' => 'already_voided',
                    'message' => 'This assignment has already been voided.',
                ];
            }

            if (isset($linkedChildAssignmentIds[$id])) {
                $blockers[] = [
                    'code' => 'linked_assignment_exists',
                    'message' => self::BLOCKED_MESSAGE,
                ];
            }

            if (! $ignoreLinkedSeaService && isset($assignmentsWithSeaService[$id])) {
                $blockers[] = [
                    'code' => 'sea_service_exists',
                    'message' => self::SEA_SERVICE_BLOCKED_MESSAGE,
                ];
            }

            if (isset($assignmentsWithAppliedPayroll[$id])) {
                $blockers[] = [
                    'code' => 'payroll_applied',
                    'message' => self::BLOCKED_MESSAGE,
                ];
            }

            if (
                isset($assignmentsWithProtectedPrep[$id])
                || isset($assignmentsWithProtectedPeriodSegments[$id])
                || isset($assignmentsWithWorkAllocations[$id])
            ) {
                $blockers[] = [
                    'code' => 'payroll_protected',
                    'message' => self::BLOCKED_MESSAGE,
                ];
            }

            if (isset($assignmentsWithSegments[$id])) {
                $blockers[] = [
                    'code' => 'protected_dependency_exists',
                    'message' => self::BLOCKED_MESSAGE,
                ];
            }

            if (isset($assignmentsWithAccommodation[$id])) {
                $blockers[] = [
                    'code' => 'accommodation_history_exists',
                    'message' => self::ACCOMMODATION_BLOCKED_MESSAGE,
                ];
            }

            $result[$id] = $this->uniqueByCode($blockers);
        }

        return $result;
    }

    /**
     * @param  iterable<CrewAssignment>  $assignments
     */
    public function assertCanVoidMany(iterable $assignments, int $companyId, bool $ignoreLinkedSeaService = false): void
    {
        $batchBlockers = $this->batchBlockers($assignments, $companyId, $ignoreLinkedSeaService);

        foreach ($assignments as $assignment) {
            $blockers = $batchBlockers[(int) $assignment->id] ?? [];
            if ($blockers !== []) {
                $this->throwBlockerValidationException($blockers);
            }
        }
    }

    /**
     * @param  list<array{code: string, message: string}>  $blockers
     */
    private function throwBlockerValidationException(array $blockers): never
    {
        $alreadyVoided = collect($blockers)->contains(
            fn (array $blocker): bool => $blocker['code'] === 'already_voided',
        );

        $accommodationBlocked = collect($blockers)->contains(
            fn (array $blocker): bool => $blocker['code'] === 'accommodation_history_exists',
        );

        $seaServiceBlocked = collect($blockers)->contains(
            fn (array $blocker): bool => $blocker['code'] === 'sea_service_exists',
        );

        throw ValidationException::withMessages([
            'void' => $alreadyVoided
                ? 'This assignment has already been voided.'
                : ($accommodationBlocked
                    ? self::ACCOMMODATION_BLOCKED_MESSAGE
                    : ($seaServiceBlocked
                        ? self::SEA_SERVICE_BLOCKED_MESSAGE
                        : self::BLOCKED_MESSAGE)),
        ]);
    }

    /**
     * @param  list<array{code: string, message: string}>  $blockers
     * @return list<array{code: string, message: string}>
     */
    private function uniqueByCode(array $blockers): array
    {
        $seen = [];
        $unique = [];

        foreach ($blockers as $blocker) {
            if (isset($seen[$blocker['code']])) {
                continue;
            }

            $seen[$blocker['code']] = true;
            $unique[] = $blocker;
        }

        return $unique;
    }
}
