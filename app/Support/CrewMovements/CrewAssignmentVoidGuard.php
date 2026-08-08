<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
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

    /**
     * @return list<array{code: string, message: string}>
     */
    public function blockers(CrewAssignment $assignment, int $companyId): array
    {
        if ((int) $assignment->company_id !== $companyId) {
            return [[
                'code' => 'cross_company',
                'message' => 'Assignment does not belong to the active company.',
            ]];
        }

        $blockers = [];

        if ($assignment->voided_at !== null || $assignment->trashed()) {
            $blockers[] = [
                'code' => 'already_voided',
                'message' => 'This assignment has already been voided.',
            ];
        }

        if ($this->hasLinkedChildAssignments($assignment, $companyId)) {
            $blockers[] = [
                'code' => 'linked_assignment_exists',
                'message' => self::BLOCKED_MESSAGE,
            ];
        }

        if ($this->hasSeaService($assignment, $companyId)) {
            $blockers[] = [
                'code' => 'sea_service_exists',
                'message' => self::BLOCKED_MESSAGE,
            ];
        }

        if ($this->hasAppliedPayroll($assignment, $companyId)) {
            $blockers[] = [
                'code' => 'payroll_applied',
                'message' => self::BLOCKED_MESSAGE,
            ];
        }

        if ($this->hasProtectedPayroll($assignment, $companyId)) {
            $blockers[] = [
                'code' => 'payroll_protected',
                'message' => self::BLOCKED_MESSAGE,
            ];
        }

        if ($this->hasProtectedDependency($assignment, $companyId)) {
            $blockers[] = [
                'code' => 'protected_dependency_exists',
                'message' => self::BLOCKED_MESSAGE,
            ];
        }

        return $this->uniqueByCode($blockers);
    }

    public function assertCanVoid(CrewAssignment $assignment, int $companyId): void
    {
        $blockers = $this->blockers($assignment, $companyId);

        if ($blockers === []) {
            return;
        }

        $alreadyVoided = collect($blockers)->contains(
            fn (array $blocker): bool => $blocker['code'] === 'already_voided',
        );

        throw ValidationException::withMessages([
            'void' => $alreadyVoided
                ? 'This assignment has already been voided.'
                : self::BLOCKED_MESSAGE,
        ]);
    }

    private function hasLinkedChildAssignments(CrewAssignment $assignment, int $companyId): bool
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('previous_assignment_id', $assignment->id)
            ->exists();
    }

    private function hasSeaService(CrewAssignment $assignment, int $companyId): bool
    {
        $phaseIds = CrewAssignmentPhase::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->pluck('id');

        if ($phaseIds->isEmpty()) {
            return false;
        }

        return EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->whereIn('crew_assignment_phase_id', $phaseIds)
            ->exists();
    }

    private function hasAppliedPayroll(CrewAssignment $assignment, int $companyId): bool
    {
        return CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->where('status', CrewTimesheetPreparationStatus::Applied);
            })
            ->exists();
    }

    private function hasProtectedPayroll(CrewAssignment $assignment, int $companyId): bool
    {
        $protectedPreparation = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        CrewTimesheetPreparationStatus::Submitted,
                        CrewTimesheetPreparationStatus::Approved,
                    ]);
            })
            ->exists();

        if ($protectedPreparation) {
            return true;
        }

        $hasSegmentOnProtectedPeriod = CrewTimesheetSegment::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        PayrollPeriodStatus::Approved,
                        PayrollPeriodStatus::Paid,
                        PayrollPeriodStatus::Processing,
                    ]);
            })
            ->exists();

        if ($hasSegmentOnProtectedPeriod) {
            return true;
        }

        return PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved,
                PayrollWorkAllocationStatus::Paid,
                PayrollWorkAllocationStatus::Reserved,
            ])
            ->exists();
    }

    private function hasProtectedDependency(CrewAssignment $assignment, int $companyId): bool
    {
        // Conservative: any Crew Operations timesheet segment for this assignment
        // is treated as a protected payroll dependency (immutable ops history).
        return CrewTimesheetSegment::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->exists();
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
