<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\PayrollCategory;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewTimelineIssueDetector
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
    ) {}

    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return list<array{
     *     employee_id: int,
     *     crew_assignment_id: int|null,
     *     crew_assignment_phase_id: int|null,
     *     phase_code: CrewPhaseCode|null,
     *     warning_code: CrewTimelineWarningCode,
     *     remarks: string,
     *     from_date: string,
     *     to_date: string
     * }>
     */
    public function detect(
        PayrollPeriod $period,
        Collection $phases,
        CarbonInterface $effectiveEnd,
        int $companyId,
    ): array {
        $issues = [];
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $periodStart = $period->start_date->toDateString();
        $periodEnd = $period->end_date->toDateString();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $employeeIds = $phases
            ->map(fn (CrewAssignmentPhase $phase) => (int) $phase->assignment?->employee_id)
            ->filter()
            ->unique()
            ->values();

        $contracts = $this->resolveContract->resolveMany($period, $employeeIds->all());

        $pendingPhaseIds = CrewMovementCorrection::query()
            ->where('company_id', $companyId)
            ->pending()
            ->whereIn('crew_assignment_phase_id', $phases->pluck('id'))
            ->pluck('crew_assignment_phase_id')
            ->all();

        foreach ($phases as $phase) {
            $assignment = $phase->assignment;
            $employeeId = (int) ($assignment?->employee_id ?? 0);

            if ($assignment === null || $employeeId < 1) {
                continue;
            }

            if ((int) $phase->company_id !== $companyId || (int) $assignment->company_id !== $companyId) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::CrossCompanyReference,
                    'Phase or assignment belongs to another company.',
                    $periodStart,
                    $periodEnd,
                );

                continue;
            }

            if ($phase->actual_start_at === null) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::MissingActualStart,
                    'Phase is missing an actual start date.',
                    $periodStart,
                    $periodEnd,
                );

                continue;
            }

            if (
                $phase->status === CrewPhaseStatus::Completed
                && $phase->actual_end_at === null
            ) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::MissingActualEnd,
                    'Completed phase is missing an actual end date.',
                    $periodStart,
                    $periodEnd,
                );
            }

            if (
                $phase->actual_end_at !== null
                && $phase->actual_end_at->lt($phase->actual_start_at)
            ) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::InvalidPhaseRange,
                    'Phase actual end is before actual start.',
                    $periodStart,
                    $periodEnd,
                );
            }

            $actualStartLocal = CarbonImmutable::parse(
                $phase->actual_start_at->timezone($timezone)->toDateString(),
                $timezone,
            )->startOfDay();

            if ($actualStartLocal->gt($today) || $actualStartLocal->gt($effectiveEnd)) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::FutureActualDate,
                    'Phase actual start is in the future and will not generate payable days.',
                    $actualStartLocal->toDateString(),
                    $actualStartLocal->toDateString(),
                );
            }

            if (in_array($phase->id, $pendingPhaseIds, true)) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::PendingMovementCorrection,
                    'Phase has a pending movement correction.',
                    $periodStart,
                    $periodEnd,
                );
            }

            $contract = $contracts->get($employeeId);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::NoActiveCrewContract,
                    'Employee has no active crew contract.',
                    $periodStart,
                    $periodEnd,
                );
            } elseif ($contract->resolvedSalaryStructure() === ContractSalaryStructure::Monthly) {
                $issues[] = $this->issue(
                    $employeeId,
                    (int) $phase->crew_assignment_id,
                    (int) $phase->id,
                    $phase->phase_code,
                    CrewTimelineWarningCode::MonthlyContractNotSupported,
                    'Monthly crew contracts are not included in automatic timeline preparation.',
                    $periodStart,
                    $periodEnd,
                );
            }
        }

        return $issues;
    }

    /**
     * @return array{
     *     employee_id: int,
     *     crew_assignment_id: int|null,
     *     crew_assignment_phase_id: int|null,
     *     phase_code: CrewPhaseCode|null,
     *     warning_code: CrewTimelineWarningCode,
     *     remarks: string,
     *     from_date: string,
     *     to_date: string
     * }
     */
    private function issue(
        int $employeeId,
        ?int $assignmentId,
        ?int $phaseId,
        ?CrewPhaseCode $phaseCode,
        CrewTimelineWarningCode $warningCode,
        string $remarks,
        string $fromDate,
        string $toDate,
    ): array {
        return [
            'employee_id' => $employeeId,
            'crew_assignment_id' => $assignmentId,
            'crew_assignment_phase_id' => $phaseId,
            'phase_code' => $phaseCode,
            'warning_code' => $warningCode,
            'remarks' => $remarks,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
    }
}
