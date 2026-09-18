<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use Carbon\CarbonInterface;

/**
 * Locks crew movement source rows participating in timeline freshness before
 * a final Apply freshness assertion. Lock order matches Crew Movement:
 * Employee → Crew Assignment → period-relevant source phases → pending
 * corrections on those phases → crew contracts.
 */
final class CrewTimelineSourceLocker
{
    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * Acquires authoritative locks over every source row that can affect the
     * preparation hash, then returns the locked current source state.
     */
    public function lockSource(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        int $companyId,
    ): LockedCrewTimelineSource {
        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);
        $employeeIds = $this->resolveBoundaryEmployeeIds($period, $preparation, $effectiveEnd, $companyId);

        if ($employeeIds === []) {
            return new LockedCrewTimelineSource(
                phases: collect(),
                contractsByEmployeeId: collect(),
                pendingCorrections: collect(),
            );
        }

        Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $phases = $this->phaseQuery->issuePhasesForUpdate($period, $effectiveEnd);

        $sourcePhaseIds = $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->id)
            ->filter(fn (int $phaseId): bool => $phaseId > 0)
            ->values()
            ->all();

        $pendingCorrections = $sourcePhaseIds === []
            ? collect()
            : CrewMovementCorrection::query()
                ->where('company_id', $companyId)
                ->pending()
                ->whereIn('crew_assignment_phase_id', $sourcePhaseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'crew_assignment_phase_id', 'status', 'updated_at']);

        $lockedContracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Crew)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $contractsByEmployeeId = $this->resolveContract->resolveManyFromCollection(
            $period,
            $employeeIds,
            $lockedContracts,
        );

        return new LockedCrewTimelineSource(
            phases: $phases,
            contractsByEmployeeId: $contractsByEmployeeId,
            pendingCorrections: $pendingCorrections,
        );
    }

    /**
     * @return list<int>
     */
    private function resolveBoundaryEmployeeIds(
        PayrollPeriod $period,
        CrewTimesheetPreparation $preparation,
        CarbonInterface $effectiveEnd,
        int $companyId,
    ): array {
        $fromPhases = $this->phaseQuery->issuePhases($period, $effectiveEnd)
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->assignment?->employee_id)
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

        $fromLines = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $employeeId): bool => $employeeId > 0);

        $fromContracts = $this->resolveContract->crewEmployeeIdsResolvableForPeriod($period);

        return collect($fromPhases->all())
            ->merge($fromLines->all())
            ->merge($fromContracts)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
