<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

/**
 * Locks crew movement source rows participating in timeline freshness before
 * a final Apply freshness assertion. Lock order matches Crew Movement:
 * employees, assignments, phases, pending corrections, then crew contracts.
 */
final class CrewTimelineSourceLocker
{
    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
    ) {}

    /**
     * Acquires authoritative locks over every source row that can affect the
     * preparation hash, then reloads issue phases under those locks.
     *
     * @return Collection<int, CrewAssignmentPhase>
     */
    public function lockAndReloadIssuePhases(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): Collection {
        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);

        $employeeIds = $this->phaseQuery->issuePhases($period, $effectiveEnd)
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->assignment?->employee_id)
            ->filter(fn (int $employeeId): bool => $employeeId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($employeeIds === []) {
            return collect();
        }

        Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $assignmentIds = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($assignmentIds !== []) {
            $phaseIds = CrewAssignmentPhase::query()
                ->where('company_id', $companyId)
                ->whereIn('crew_assignment_id', $assignmentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($phaseIds !== []) {
                CrewMovementCorrection::query()
                    ->where('company_id', $companyId)
                    ->pending()
                    ->whereIn('crew_assignment_phase_id', $phaseIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }
        }

        EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $this->phaseQuery->issuePhasesForUpdate($period, $effectiveEnd);
    }
}
