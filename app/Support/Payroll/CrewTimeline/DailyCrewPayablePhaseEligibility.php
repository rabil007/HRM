<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Determines whether an open crew phase can affect automatic Daily Crew payable
 * allocation. Used to avoid false overnight staleness when only Monthly Crew or
 * excluded phases remain open.
 */
final class DailyCrewPayablePhaseEligibility
{
    public function __construct(
        private readonly CrewPhasePayCategoryResolver $categoryResolver,
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    public function isOpenPayableDailyPhase(
        CrewAssignmentPhase $phase,
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
        ?EmployeeContract $contract = null,
    ): bool {
        if ($phase->actual_start_at === null) {
            return false;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $period->company_id);
        $phaseStart = CarbonImmutable::parse($phase->actual_start_at, $timezone)->startOfDay();

        if ($phaseStart->gt($effectiveEnd)) {
            return false;
        }

        if ($phase->actual_end_at === null) {
            $isOpen = true;
        } else {
            $phaseEnd = CarbonImmutable::parse($phase->actual_end_at, $timezone)->startOfDay();
            $isOpen = $phaseEnd->gt($effectiveEnd);
        }

        if (! $isOpen) {
            return false;
        }

        $assignment = $phase->assignment;

        if (
            $assignment === null
            || (int) $phase->company_id !== (int) $period->company_id
            || (int) $assignment->company_id !== (int) $period->company_id
        ) {
            return false;
        }

        $employeeId = (int) $assignment->employee_id;

        if ($employeeId < 1) {
            return false;
        }

        if ($contract === null) {
            $contract = $this->resolveContract->resolveMany($period, [$employeeId])->get($employeeId);
        }

        if (
            $contract === null
            || $contract->payroll_category !== PayrollCategory::Crew
            || $contract->resolvedSalaryStructure() === ContractSalaryStructure::Monthly
        ) {
            return false;
        }

        return $this->categoryResolver->resolve($phase->phase_code) !== CrewTimesheetPayCategory::Excluded;
    }

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     */
    public function hasOpenPayableDailyPhase(
        PayrollPeriod $period,
        CarbonInterface $effectiveEnd,
        Collection $phases,
    ): bool {
        $employeeIds = $phases
            ->map(fn (CrewAssignmentPhase $phase): int => (int) $phase->assignment?->employee_id)
            ->filter(fn (int $employeeId): bool => $employeeId > 0)
            ->unique()
            ->values()
            ->all();

        $contracts = $this->resolveContract->resolveMany($period, $employeeIds);

        foreach ($phases as $phase) {
            $employeeId = (int) ($phase->assignment?->employee_id ?? 0);
            $contract = $employeeId > 0 ? $contracts->get($employeeId) : null;

            if ($this->isOpenPayableDailyPhase($phase, $period, $effectiveEnd, $contract)) {
                return true;
            }
        }

        return false;
    }
}
