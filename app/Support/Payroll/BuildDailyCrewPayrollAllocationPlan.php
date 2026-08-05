<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollWorkAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds day-level Daily Crew payroll allocations for current and prior periods.
 *
 * @phpstan-type AllocationDay array{
 *     work_date: string,
 *     pay_category: string,
 *     period_classification: string,
 *     contract_id: int,
 *     salary_revision_id: int|null,
 *     basic_daily_rate: float,
 *     site_allowance_daily_rate: float,
 *     supplementary_allowance_daily_rate: float,
 *     basic_amount: float,
 *     site_allowance_amount: float,
 *     supplementary_allowance_amount: float,
 *     total_amount: float,
 *     crew_timesheet_segment_id: int|null,
 *     source: string|null,
 *     crew_assignment_id: int|null,
 *     crew_assignment_phase_id: int|null
 * }
 * @phpstan-type EarningPeriod array{
 *     from_date: string,
 *     to_date: string,
 *     days: int,
 *     pay_category: string,
 *     period_classification: string,
 *     contract_id: int,
 *     salary_revision_id: int|null,
 *     basic_daily_rate: float,
 *     site_allowance_daily_rate: float,
 *     supplementary_allowance_daily_rate: float,
 *     amount: float,
 *     basic_amount: float,
 *     site_allowance_amount: float,
 *     supplementary_allowance_amount: float
 * }
 * @phpstan-type PreviewIssue array{
 *     employee_id: int|null,
 *     employee_name: string|null,
 *     code: string,
 *     message: string,
 *     work_date: string|null,
 *     from_date: string|null,
 *     to_date: string|null,
 *     pay_category: string|null,
 *     contract_id: int|null,
 *     salary_revision_id: int|null,
 *     competing_payroll_period_id: int|null
 * }
 */
final class BuildDailyCrewPayrollAllocationPlan
{
    public function __construct(
        private readonly ResolveCrewContractForWorkDate $resolveContractForWorkDate,
        private readonly ResolveEffectiveContractSalaryComponents $resolveEffectiveComponents,
    ) {}

    /**
     * @return array{
     *     days: list<AllocationDay>,
     *     earning_periods: list<EarningPeriod>,
     *     issues: list<PreviewIssue>,
     *     warnings: list<string>,
     *     excluded_already_paid: list<array{work_date: string, pay_category: string, period_classification: string}>,
     *     reserved_conflicts: list<array{work_date: string, competing_payroll_period_id: int}>,
     *     requested_prior_days: int,
     *     payable_prior_days: int,
     *     current_days: int
     * }
     */
    public function handle(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        ?int $ignorePayrollRecordId = null,
    ): array {
        $companyId = (int) $period->company_id;
        $employeeId = (int) $timesheet->employee_id;
        $periodStart = CarbonImmutable::parse($period->start_date)->startOfDay();
        $periodEnd = CarbonImmutable::parse($period->end_date)->startOfDay();

        $timesheet->loadMissing(['segments', 'employee']);

        $contracts = $this->resolveContractForWorkDate->loadContracts($companyId, $employeeId);
        $existingAllocations = $this->loadExistingAllocations($companyId, $employeeId, $ignorePayrollRecordId);

        $issues = [];
        $days = [];
        $excludedAlreadyPaid = [];
        $reservedConflicts = [];
        $warnings = [];
        $requestedPriorDays = 0;
        $payablePriorDays = 0;
        $currentDays = 0;

        foreach ($timesheet->segments as $segment) {
            if (! $segment->pay_category instanceof CrewTimesheetPayCategory
                || $segment->pay_category === CrewTimesheetPayCategory::Excluded
                || $segment->from_date === null
                || $segment->to_date === null) {
                continue;
            }

            $from = CarbonImmutable::parse($segment->from_date)->startOfDay();
            $to = CarbonImmutable::parse($segment->to_date)->startOfDay();
            $inclusiveDays = (int) ($from->diffInDays($to) + 1);

            if ($segment->days !== null && (int) $segment->days !== $inclusiveDays) {
                $issues[] = $this->issue(
                    $timesheet,
                    'fractional_or_mismatched_days',
                    'Segment days must equal the inclusive calendar day count (fractional days are not supported).',
                    $from->toDateString(),
                    $from->toDateString(),
                    $to->toDateString(),
                    $segment->pay_category->value,
                );

                continue;
            }

            if ($from->greaterThan($periodEnd) || $to->greaterThan($periodEnd)) {
                $issues[] = $this->issue(
                    $timesheet,
                    'future_date',
                    'Movement dates after the payroll period end are not allowed.',
                    $from->toDateString(),
                    $from->toDateString(),
                    $to->toDateString(),
                    $segment->pay_category->value,
                );

                continue;
            }

            for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
                if ($cursor->greaterThan($periodEnd)) {
                    $issues[] = $this->issue(
                        $timesheet,
                        'future_date',
                        "Work date {$cursor->toDateString()} is after the payroll period end.",
                        $cursor->toDateString(),
                        $from->toDateString(),
                        $to->toDateString(),
                        $segment->pay_category->value,
                    );

                    continue;
                }

                $classification = $cursor->lessThan($periodStart)
                    ? PayrollWorkPeriodClassification::Prior
                    : PayrollWorkPeriodClassification::Current;

                if ($classification === PayrollWorkPeriodClassification::Prior) {
                    $requestedPriorDays++;
                }

                $built = $this->buildDay(
                    $timesheet,
                    $contracts,
                    $cursor->toDateString(),
                    $segment->pay_category,
                    $classification,
                    $existingAllocations,
                    segmentId: (int) $segment->id,
                    source: $segment->source?->value,
                    assignmentId: $segment->crew_assignment_id !== null ? (int) $segment->crew_assignment_id : null,
                    phaseId: $segment->crew_assignment_phase_id !== null ? (int) $segment->crew_assignment_phase_id : null,
                );

                if ($built['issue'] !== null) {
                    $issues[] = $built['issue'];

                    if (($built['issue']['code'] ?? null) === 'reserved_conflict'
                        && ($built['issue']['competing_payroll_period_id'] ?? null) !== null) {
                        $reservedConflicts[] = [
                            'work_date' => $cursor->toDateString(),
                            'competing_payroll_period_id' => (int) $built['issue']['competing_payroll_period_id'],
                        ];
                    }

                    continue;
                }

                if ($built['excluded'] !== null) {
                    $excludedAlreadyPaid[] = $built['excluded'];

                    continue;
                }

                $days[] = $built['day'];

                if ($classification === PayrollWorkPeriodClassification::Prior) {
                    $payablePriorDays++;
                } else {
                    $currentDays++;
                }
            }
        }

        if ($requestedPriorDays > 0 && $payablePriorDays === 0 && $excludedAlreadyPaid !== [] && $issues === []) {
            $warnings[] = 'All requested prior-period days were already paid in another payroll and were excluded.';
        }

        usort($days, fn (array $left, array $right): int => [$left['work_date'], $left['pay_category']]
            <=> [$right['work_date'], $right['pay_category']]);

        $earningPeriods = $this->groupEarningPeriods($days);

        return [
            'days' => $days,
            'earning_periods' => $earningPeriods,
            // Alias retained for callers still reading presentation_lines.
            'presentation_lines' => $earningPeriods,
            'issues' => $issues,
            'warnings' => $warnings,
            'excluded_already_paid' => $excludedAlreadyPaid,
            'reserved_conflicts' => $reservedConflicts,
            'requested_prior_days' => $requestedPriorDays,
            'payable_prior_days' => $payablePriorDays,
            'current_days' => $currentDays,
        ];
    }

    /**
     * @return array{
     *     days: list<AllocationDay>,
     *     earning_periods: list<EarningPeriod>,
     *     issues: list<PreviewIssue>,
     *     warnings: list<string>,
     *     excluded_already_paid: list<array{work_date: string, pay_category: string, period_classification: string}>,
     *     reserved_conflicts: list<array{work_date: string, competing_payroll_period_id: int}>,
     *     requested_prior_days: int,
     *     payable_prior_days: int,
     *     current_days: int
     * }
     *
     * @throws ValidationException
     */
    public function handleOrFail(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        ?int $ignorePayrollRecordId = null,
    ): array {
        $plan = $this->handle($period, $timesheet, $ignorePayrollRecordId);

        if ($plan['issues'] !== []) {
            throw ValidationException::withMessages([
                'employee_id' => $plan['issues'][0]['message'],
            ]);
        }

        return $plan;
    }

    /**
     * @param  Collection<int, EmployeeContract>  $contracts
     * @param  array<string, array{status: string, payroll_period_id: int, payroll_record_id: int|null}>  $existingAllocations
     * @return array{
     *     day: ?AllocationDay,
     *     excluded: ?array{work_date: string, pay_category: string, period_classification: string},
     *     issue: ?PreviewIssue
     * }
     */
    private function buildDay(
        CrewTimesheet $timesheet,
        Collection $contracts,
        string $workDate,
        CrewTimesheetPayCategory $payCategory,
        PayrollWorkPeriodClassification $classification,
        array $existingAllocations,
        ?int $segmentId = null,
        ?string $source = null,
        ?int $assignmentId = null,
        ?int $phaseId = null,
    ): array {
        if (isset($existingAllocations[$workDate])) {
            $existing = $existingAllocations[$workDate];
            $status = PayrollWorkAllocationStatus::tryFrom($existing['status']);

            if (in_array($status, [PayrollWorkAllocationStatus::Approved, PayrollWorkAllocationStatus::Paid], true)) {
                return [
                    'day' => null,
                    'excluded' => [
                        'work_date' => $workDate,
                        'pay_category' => $payCategory->value,
                        'period_classification' => $classification->value,
                    ],
                    'issue' => null,
                ];
            }

            if ($status === PayrollWorkAllocationStatus::Reserved) {
                return [
                    'day' => null,
                    'excluded' => null,
                    'issue' => $this->issue(
                        $timesheet,
                        'reserved_conflict',
                        "Work date {$workDate} is already reserved by another open payroll period.",
                        $workDate,
                        $workDate,
                        $workDate,
                        $payCategory->value,
                        competingPayrollPeriodId: (int) $existing['payroll_period_id'],
                    ),
                ];
            }
        }

        $resolved = $this->resolveContractForWorkDate->resolve(
            (int) $timesheet->company_id,
            (int) $timesheet->employee_id,
            $workDate,
            $contracts,
        );

        if ($resolved['contract'] === null) {
            return [
                'day' => null,
                'excluded' => null,
                'issue' => $this->issue(
                    $timesheet,
                    $resolved['issue']['code'],
                    $resolved['issue']['message'],
                    $workDate,
                    $workDate,
                    $workDate,
                    $payCategory->value,
                ),
            ];
        }

        /** @var EmployeeContract $contract */
        $contract = $resolved['contract'];
        $revisionResult = $this->resolveContractForWorkDate->resolveSalaryRevision($contract, $workDate);

        if ($revisionResult['issue'] !== null) {
            return [
                'day' => null,
                'excluded' => null,
                'issue' => $this->issue(
                    $timesheet,
                    $revisionResult['issue']['code'],
                    $revisionResult['issue']['message'],
                    $workDate,
                    $workDate,
                    $workDate,
                    $payCategory->value,
                    (int) $contract->id,
                ),
            ];
        }

        $components = $this->resolveEffectiveComponents->handle($contract, CarbonImmutable::parse($workDate));
        $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic);

        if ($basicRate === null) {
            return [
                'day' => null,
                'excluded' => null,
                'issue' => $this->issue(
                    $timesheet,
                    'missing_basic_daily_rate',
                    "Active basic daily rate is required for work date {$workDate}.",
                    $workDate,
                    $workDate,
                    $workDate,
                    $payCategory->value,
                    (int) $contract->id,
                    $revisionResult['revision']?->id,
                ),
            ];
        }

        $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance) ?? 0.0;
        $supplementaryRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance) ?? 0.0;

        if ($payCategory === CrewTimesheetPayCategory::Onsite) {
            $basicAmount = round($basicRate, 2);
            $siteAmount = round($siteRate, 2);
            $supplementaryAmount = round($supplementaryRate, 2);
        } else {
            // Standby: store basic and supplementary separately (not combined into basic_amount).
            $basicAmount = round($basicRate, 2);
            $siteAmount = 0.0;
            $supplementaryAmount = round($supplementaryRate, 2);
        }

        $total = round($basicAmount + $siteAmount + $supplementaryAmount, 2);

        return [
            'day' => [
                'work_date' => $workDate,
                'pay_category' => $payCategory->value,
                'period_classification' => $classification->value,
                'contract_id' => (int) $contract->id,
                'salary_revision_id' => $revisionResult['revision']?->id !== null
                    ? (int) $revisionResult['revision']->id
                    : null,
                'basic_daily_rate' => $basicRate,
                'site_allowance_daily_rate' => $siteRate,
                'supplementary_allowance_daily_rate' => $supplementaryRate,
                'basic_amount' => $basicAmount,
                'site_allowance_amount' => $siteAmount,
                'supplementary_allowance_amount' => $supplementaryAmount,
                'total_amount' => $total,
                'crew_timesheet_segment_id' => $segmentId,
                'source' => $source,
                'crew_assignment_id' => $assignmentId,
                'crew_assignment_phase_id' => $phaseId,
            ],
            'excluded' => null,
            'issue' => null,
        ];
    }

    /**
     * @return array<string, array{status: string, payroll_period_id: int, payroll_record_id: int|null}>
     */
    private function loadExistingAllocations(
        int $companyId,
        int $employeeId,
        ?int $ignorePayrollRecordId,
    ): array {
        $query = PayrollWorkAllocation::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', PayrollWorkAllocationStatus::activeValues());

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        if ($ignorePayrollRecordId !== null) {
            $query->where(function ($inner) use ($ignorePayrollRecordId): void {
                $inner->where('payroll_record_id', '!=', $ignorePayrollRecordId)
                    ->orWhereNull('payroll_record_id');
            });
        }

        $map = [];

        foreach ($query->get(['work_date', 'status', 'payroll_period_id', 'payroll_record_id']) as $row) {
            $date = CarbonImmutable::parse($row->work_date)->toDateString();
            $status = $row->status instanceof PayrollWorkAllocationStatus
                ? $row->status->value
                : (string) $row->status;

            // Prefer approved/paid over reserved when multiple rows somehow exist.
            if (isset($map[$date])
                && in_array($map[$date]['status'], [
                    PayrollWorkAllocationStatus::Approved->value,
                    PayrollWorkAllocationStatus::Paid->value,
                ], true)) {
                continue;
            }

            $map[$date] = [
                'status' => $status,
                'payroll_period_id' => (int) $row->payroll_period_id,
                'payroll_record_id' => $row->payroll_record_id !== null ? (int) $row->payroll_record_id : null,
            ];
        }

        return $map;
    }

    /**
     * @param  list<AllocationDay>  $days
     * @return list<EarningPeriod>
     */
    private function groupEarningPeriods(array $days): array
    {
        $lines = [];
        $current = null;

        foreach ($days as $day) {
            if ($current === null) {
                $current = $this->startEarningPeriod($day);

                continue;
            }

            $expectedNext = CarbonImmutable::parse($current['to_date'])->addDay()->toDateString();
            $sameGroup = $day['pay_category'] === $current['pay_category']
                && $day['period_classification'] === $current['period_classification']
                && $day['contract_id'] === $current['contract_id']
                && $day['salary_revision_id'] === $current['salary_revision_id']
                && $day['basic_daily_rate'] === $current['basic_daily_rate']
                && $day['site_allowance_daily_rate'] === $current['site_allowance_daily_rate']
                && $day['supplementary_allowance_daily_rate'] === $current['supplementary_allowance_daily_rate']
                && $day['work_date'] === $expectedNext;

            if (! $sameGroup) {
                $lines[] = $current;
                $current = $this->startEarningPeriod($day);

                continue;
            }

            $current['to_date'] = $day['work_date'];
            $current['days']++;
            $current['amount'] = round($current['amount'] + $day['total_amount'], 2);
            $current['basic_amount'] = round($current['basic_amount'] + $day['basic_amount'], 2);
            $current['site_allowance_amount'] = round($current['site_allowance_amount'] + $day['site_allowance_amount'], 2);
            $current['supplementary_allowance_amount'] = round(
                $current['supplementary_allowance_amount'] + $day['supplementary_allowance_amount'],
                2,
            );
        }

        if ($current !== null) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @param  AllocationDay  $day
     * @return EarningPeriod
     */
    private function startEarningPeriod(array $day): array
    {
        return [
            'from_date' => $day['work_date'],
            'to_date' => $day['work_date'],
            'days' => 1,
            'pay_category' => $day['pay_category'],
            'period_classification' => $day['period_classification'],
            'contract_id' => $day['contract_id'],
            'salary_revision_id' => $day['salary_revision_id'],
            'basic_daily_rate' => $day['basic_daily_rate'],
            'site_allowance_daily_rate' => $day['site_allowance_daily_rate'],
            'supplementary_allowance_daily_rate' => $day['supplementary_allowance_daily_rate'],
            'amount' => $day['total_amount'],
            'basic_amount' => $day['basic_amount'],
            'site_allowance_amount' => $day['site_allowance_amount'],
            'supplementary_allowance_amount' => $day['supplementary_allowance_amount'],
        ];
    }

    /**
     * @return PreviewIssue
     */
    private function issue(
        CrewTimesheet $timesheet,
        string $code,
        string $message,
        ?string $workDate = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $payCategory = null,
        ?int $contractId = null,
        ?int $salaryRevisionId = null,
        ?int $competingPayrollPeriodId = null,
    ): array {
        return [
            'employee_id' => (int) $timesheet->employee_id,
            'employee_name' => $timesheet->employee?->name,
            'code' => $code,
            'message' => $message,
            'work_date' => $workDate,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'pay_category' => $payCategory,
            'contract_id' => $contractId,
            'salary_revision_id' => $salaryRevisionId,
            'competing_payroll_period_id' => $competingPayrollPeriodId,
        ];
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function activeAmount(Collection $components, SalaryComponentCode $code): ?float
    {
        $component = $components->first(
            fn (ContractSalaryComponent $item) => $item->component_code === $code
                && $item->status === SalaryComponentStatus::Active,
        );

        if ($component === null || (float) $component->amount <= 0) {
            return null;
        }

        return (float) $component->amount;
    }
}
