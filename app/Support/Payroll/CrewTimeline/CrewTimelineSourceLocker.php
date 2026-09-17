<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheetPreparation;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

/**
 * Locks crew movement source rows participating in timeline freshness before
 * a final Apply freshness assertion. Lock order matches Crew Movement:
 * assignments, then phases, then pending corrections and contracts.
 */
final class CrewTimelineSourceLocker
{
    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
    ) {}

    /**
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function lockAndReloadIssuePhases(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): Collection {
        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);
        $phases = $this->phaseQuery->issuePhases($period, $effectiveEnd);

        $assignmentIds = $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->crew_assignment_id)
            ->filter(fn (int $assignmentId): bool => $assignmentId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $phaseIds = $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->id)
            ->sort()
            ->values()
            ->all();

        $employeeIds = $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->assignment?->employee_id)
            ->filter(fn (int $employeeId): bool => $employeeId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($assignmentIds !== []) {
            CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        if ($phaseIds !== []) {
            CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $phaseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            CrewMovementCorrection::query()
                ->where('company_id', $companyId)
                ->pending()
                ->whereIn('crew_assignment_phase_id', $phaseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        if ($employeeIds !== []) {
            EmployeeContract::query()
                ->where('company_id', $companyId)
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }

        return $this->phaseQuery->issuePhases($period, $effectiveEnd);
    }
}
