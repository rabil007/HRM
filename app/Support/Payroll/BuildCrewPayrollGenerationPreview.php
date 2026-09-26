<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BuildCrewPayrollGenerationPreview
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly ValidateCrewTimesheetOperationalIntegrity $validateIntegrity,
        private readonly CrewOperationsPayrollGenerationGuard $legacyGuard,
        private readonly BuildDailyCrewPayrollAllocationPlan $buildAllocationPlan,
    ) {}

    /**
     * @param  list<int>  $excludedEmployeeIds
     */
    public function handle(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds = [],
        ?User $user = null,
    ): CrewPayrollGenerationPreview {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if (! $period->isCrew()) {
            return $this->emptyReadyPreview();
        }

        $excludedEmployeeIds = array_values(array_unique(array_map(
            intval(...),
            array_merge($period->excluded_employee_ids ?? [], $excludedEmployeeIds),
        )));

        $visibleExcludedEmployeeIds = $this->visibleExcludedIds($excludedEmployeeIds, $companyId, $user);

        if ($period->requiresExclusiveCrewOperationsTimesheets()) {
            return $this->exclusiveCrewOperationsPreview($period, $companyId, $excludedEmployeeIds, $visibleExcludedEmployeeIds, $user);
        }

        return $this->hybridOrManualPreview($period, $companyId, $excludedEmployeeIds, $visibleExcludedEmployeeIds, $user);
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     * @param  list<int>  $visibleExcludedEmployeeIds
     */
    private function exclusiveCrewOperationsPreview(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds,
        array $visibleExcludedEmployeeIds,
        ?User $user = null,
    ): CrewPayrollGenerationPreview {
        $employees = $this->loadEmployees($companyId, $excludedEmployeeIds, $user);
        $legacy = $this->legacyGuard->validateReadiness($period, $employees, $companyId);
        $skippedIssues = $this->skippedIssuesForExcluded($companyId, $visibleExcludedEmployeeIds);

        if (! $legacy['ready']) {
            $blocking = [[
                'employee_id' => $legacy['affected_employee_id'],
                'employee_name' => null,
                'code' => 'exclusive_crew_operations',
                'message' => (string) ($legacy['blocking_reason'] ?? CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE),
                'action' => $this->actionForCode('exclusive_crew_operations'),
            ]];

            return new CrewPayrollGenerationPreview(
                ready: false,
                canGenerate: false,
                readyEmployeeIds: [],
                readyCount: 0,
                missingTimesheetEmployeeIds: [],
                missingTimesheetCount: 0,
                awaitingApprovalEmployeeIds: [],
                awaitingApprovalCount: 0,
                excludedEmployeeIds: $visibleExcludedEmployeeIds,
                excludedCount: count($visibleExcludedEmployeeIds),
                blockingIssues: $blocking,
                blockingCount: 1,
                appliedPreparationId: $legacy['applied_preparation_id'],
                appliedPreparationVersion: $legacy['applied_preparation_version'],
                periodBlockingReason: $legacy['blocking_reason'],
                skippedIssues: $skippedIssues,
                skippedCount: count($skippedIssues),
            );
        }

        $readyIds = $employees->pluck('id')->map(intval(...))->values()->all();

        return new CrewPayrollGenerationPreview(
            ready: true,
            canGenerate: $readyIds !== [],
            readyEmployeeIds: $readyIds,
            readyCount: count($readyIds),
            missingTimesheetEmployeeIds: [],
            missingTimesheetCount: 0,
            awaitingApprovalEmployeeIds: [],
            awaitingApprovalCount: 0,
            excludedEmployeeIds: $visibleExcludedEmployeeIds,
            excludedCount: count($visibleExcludedEmployeeIds),
            blockingIssues: [],
            blockingCount: 0,
            appliedPreparationId: $legacy['applied_preparation_id'],
            appliedPreparationVersion: $legacy['applied_preparation_version'],
            skippedIssues: $skippedIssues,
            skippedCount: count($skippedIssues),
        );
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     * @param  list<int>  $visibleExcludedEmployeeIds
     */
    private function hybridOrManualPreview(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds,
        array $visibleExcludedEmployeeIds,
        ?User $user = null,
    ): CrewPayrollGenerationPreview {
        $allEmployeesQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($allEmployeesQuery, $user, $companyId);
        }

        $allEmployees = $allEmployeesQuery
            ->orderBy('employees.name')
            ->get();

        $included = $allEmployees->reject(
            fn (Employee $employee): bool => in_array((int) $employee->id, $excludedEmployeeIds, true),
        )->values();

        $contracts = $this->resolveContract->resolveMany(
            $period,
            $included->pluck('id')->map(intval(...))->all(),
        );

        $timesheets = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $included->pluck('id')->map(intval(...))->all() ?: [0])
            ->with(['preparation', 'segments', 'employee'])
            ->get()
            ->keyBy(fn (CrewTimesheet $timesheet) => (int) $timesheet->employee_id);

        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->get();

        $blockingIssues = [];
        $warningIssues = [];
        $skippedIssues = $this->skippedIssuesForExcluded($companyId, $visibleExcludedEmployeeIds);
        $automaticAdjustments = [];
        $readyIds = [];
        $missingIds = [];
        $awaitingIds = [];

        if ($applied->count() > 1) {
            $blockingIssues[] = [
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'multiple_applied_preparations',
                'message' => CrewOperationsPayrollGenerationGuard::MULTIPLE_APPLIED_MESSAGE,
                'action' => $this->actionForCode('multiple_applied_preparations'),
            ];
        }

        /** @var CrewTimesheetPreparation|null $preparation */
        $preparation = $applied->count() === 1 ? $applied->first() : null;

        if ($preparation !== null && $this->legacyGuard->preparationHasNonBypassableIntegrityProblems($preparation)) {
            $blockingIssues[] = [
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'preparation_integrity_violation',
                'message' => CrewOperationsPayrollGenerationGuard::BLOCKING_WARNINGS_MESSAGE,
                'action' => $this->actionForCode('preparation_integrity_violation'),
            ];
        }

        foreach ($included as $employee) {
            /** @var Employee $employee */
            $employeeId = (int) $employee->id;
            $contract = $contracts->get($employeeId);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                $blockingIssues[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'code' => 'missing_crew_contract',
                    'message' => "{$employee->name} has no active crew contract for this pay period.",
                    'action' => $this->actionForCode('missing_crew_contract'),
                ];

                continue;
            }

            $structure = $contract->resolvedSalaryStructure();
            $timesheet = $timesheets->get($employeeId);

            if ($structure === ContractSalaryStructure::Monthly) {
                if ($timesheet === null) {
                    $readyIds[] = $employeeId;

                    continue;
                }

                $monthlySource = $timesheet->resolvedSource();

                if ($monthlySource === CrewTimesheetSource::CrewOperations) {
                    $blockingIssues[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->name,
                        'code' => 'invalid_source_for_monthly',
                        'message' => "{$employee->name} is Monthly Crew but has a Crew Assignment timesheet.",
                        'action' => $this->actionForCode('invalid_source_for_monthly'),
                    ];

                    continue;
                }

                $readyIds[] = $employeeId;

                continue;
            }

            if ($timesheet === null) {
                $missingIds[] = $employeeId;
                $skippedIssues[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'code' => 'missing_timesheet',
                    'message' => 'No Crew Timesheet entered. This employee will be skipped from this payroll.',
                    'action' => 'Enter or Populate a Crew Timesheet for this employee in the Draft payroll period.',
                ];

                continue;
            }

            $source = $timesheet->resolvedSource();

            if ($source === CrewTimesheetSource::CrewOperations) {
                if ($this->appendIntegrityFindings(
                    $timesheet,
                    $employee,
                    $blockingIssues,
                    $warningIssues,
                )) {
                    continue;
                }

                if ($this->appendDailyAllocationPlan(
                    $period,
                    $timesheet,
                    $employee,
                    $blockingIssues,
                    $automaticAdjustments,
                )) {
                    continue;
                }

                $readyIds[] = $employeeId;

                continue;
            }

            if (! in_array($source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true)) {
                $blockingIssues[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'code' => 'invalid_timesheet_source',
                    'message' => "{$employee->name} timesheet source must be Manual, Import, or Crew Assignments.",
                    'action' => $this->actionForCode('invalid_timesheet_source'),
                ];

                continue;
            }

            if ($this->appendIntegrityFindings(
                $timesheet,
                $employee,
                $blockingIssues,
                $warningIssues,
            )) {
                continue;
            }

            if ($this->appendDailyAllocationPlan(
                $period,
                $timesheet,
                $employee,
                $blockingIssues,
                $automaticAdjustments,
            )) {
                continue;
            }

            $readyIds[] = $employeeId;
        }

        $blockingCount = count($blockingIssues);
        $warningCount = count($warningIssues);
        $readyCount = count($readyIds);
        $periodBlocking = null;

        if ($blockingCount > 0 && ($blockingIssues[0]['employee_id'] ?? null) === null) {
            $periodBlocking = $blockingIssues[0]['message'];
        }

        $ready = $blockingCount === 0;

        return new CrewPayrollGenerationPreview(
            ready: $ready,
            canGenerate: $ready && $readyCount > 0,
            readyEmployeeIds: $readyIds,
            readyCount: $readyCount,
            missingTimesheetEmployeeIds: $missingIds,
            missingTimesheetCount: count($missingIds),
            awaitingApprovalEmployeeIds: $awaitingIds,
            awaitingApprovalCount: count($awaitingIds),
            excludedEmployeeIds: $visibleExcludedEmployeeIds,
            excludedCount: count($visibleExcludedEmployeeIds),
            blockingIssues: $blockingIssues,
            blockingCount: $blockingCount,
            appliedPreparationId: $preparation?->id,
            appliedPreparationVersion: $preparation?->version,
            periodBlockingReason: $periodBlocking,
            warningIssues: $warningIssues,
            warningCount: $warningCount,
            skippedIssues: $skippedIssues,
            skippedCount: count($skippedIssues),
            automaticAdjustments: $automaticAdjustments,
            automaticAdjustmentCount: count($automaticAdjustments),
        );
    }

    /**
     * Appends integrity findings. Returns true when blocking findings were found.
     *
     * @param  list<array<string, mixed>>  $blockingIssues
     * @param  list<array<string, mixed>>  $warningIssues
     */
    private function appendIntegrityFindings(
        CrewTimesheet $timesheet,
        Employee $employee,
        array &$blockingIssues,
        array &$warningIssues,
    ): bool {
        $integrity = $this->validateIntegrity->handle($timesheet, $employee);

        foreach ($integrity->warnings as $warning) {
            $warningIssues[] = [
                'employee_id' => (int) $employee->id,
                'employee_name' => $employee->name,
                'code' => $warning['code'],
                'message' => $warning['message'],
                'pay_category' => $warning['pay_category'],
                'action' => $this->actionForCode((string) $warning['code']),
            ];
        }

        if (! $integrity->hasBlocking()) {
            return false;
        }

        foreach ($integrity->blocking as $issue) {
            $code = (string) $issue['code'];
            $blockingIssues[] = [
                'employee_id' => (int) $employee->id,
                'employee_name' => $employee->name,
                'code' => $code,
                'message' => $issue['message'],
                'pay_category' => $issue['pay_category'],
                'action' => $this->actionForCode($code),
            ];
        }

        return true;
    }

    /**
     * Runs the daily allocation plan, appends non-blocking automatic adjustments,
     * then appends blocking issues when present.
     *
     * @param  list<array<string, mixed>>  $blockingIssues
     * @param  list<array<string, mixed>>  $automaticAdjustments
     * @return bool True when blocking allocation issues were found
     */
    private function appendDailyAllocationPlan(
        PayrollPeriod $period,
        CrewTimesheet $timesheet,
        Employee $employee,
        array &$blockingIssues,
        array &$automaticAdjustments,
    ): bool {
        $timesheet->loadMissing(['segments']);

        if ($timesheet->segments->isEmpty()) {
            return false;
        }

        $existingRecordId = PayrollRecord::query()
            ->where('company_id', (int) $period->company_id)
            ->where('period_id', (int) $period->id)
            ->where('employee_id', (int) $timesheet->employee_id)
            ->value('id');

        $plan = $this->buildAllocationPlan->handle(
            $period,
            $timesheet,
            $existingRecordId !== null ? (int) $existingRecordId : null,
        );

        $this->appendAutomaticAdjustmentsFromPlan($employee, $plan, $automaticAdjustments);

        if ($plan['issues'] === []) {
            return false;
        }

        foreach ($plan['issues'] as $issue) {
            $code = (string) ($issue['code'] ?? 'allocation_plan_issue');
            $blockingIssues[] = [
                'employee_id' => $issue['employee_id'] ?? (int) $timesheet->employee_id,
                'employee_name' => $issue['employee_name'] ?? $employee->name,
                'code' => $code,
                'message' => (string) ($issue['message'] ?? 'Daily crew allocation could not be resolved.'),
                'work_date' => $issue['work_date'] ?? null,
                'from_date' => $issue['from_date'] ?? null,
                'to_date' => $issue['to_date'] ?? null,
                'pay_category' => $issue['pay_category'] ?? null,
                'contract_id' => $issue['contract_id'] ?? null,
                'salary_revision_id' => $issue['salary_revision_id'] ?? null,
                'competing_payroll_period_id' => $issue['competing_payroll_period_id'] ?? null,
                'action' => $this->actionForCode($code),
            ];
        }

        return true;
    }

    /**
     * @param  array{
     *     days: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     excluded_already_paid: list<array{work_date: string, pay_category: string, period_classification: string}>,
     *     payable_prior_days: int,
     *     requested_prior_days: int
     * }  $plan
     * @param  list<array<string, mixed>>  $automaticAdjustments
     */
    private function appendAutomaticAdjustmentsFromPlan(
        Employee $employee,
        array $plan,
        array &$automaticAdjustments,
    ): void {
        $employeeId = (int) $employee->id;
        $employeeName = $employee->name;

        $excludedByCategory = [];
        foreach ($plan['excluded_already_paid'] as $excluded) {
            $category = (string) ($excluded['pay_category'] ?? 'unknown');
            $excludedByCategory[$category][] = (string) $excluded['work_date'];
        }

        foreach ($excludedByCategory as $payCategory => $dates) {
            foreach ($this->contiguousDateRanges($dates) as $range) {
                $automaticAdjustments[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employeeName,
                    'code' => 'already_paid_prior_dates',
                    'message' => sprintf(
                        '%s already paid in a previous payroll — excluded from this payroll to prevent duplicate payment.',
                        $this->formatDateRange($range['from'], $range['to']),
                    ),
                    'from_date' => $range['from'],
                    'to_date' => $range['to'],
                    'pay_category' => $payCategory,
                    'action' => 'No action needed. These dates stay paid under the earlier payroll.',
                ];
            }
        }

        $priorDatesByCategory = [];
        foreach ($plan['days'] as $day) {
            if (($day['period_classification'] ?? null) !== PayrollWorkPeriodClassification::Prior->value) {
                continue;
            }

            $category = (string) ($day['pay_category'] ?? 'unknown');
            $priorDatesByCategory[$category][] = (string) $day['work_date'];
        }

        foreach ($priorDatesByCategory as $payCategory => $dates) {
            foreach ($this->contiguousDateRanges($dates) as $range) {
                $automaticAdjustments[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employeeName,
                    'code' => 'prior_period_arrears_included',
                    'message' => sprintf(
                        '%s prior-period work detected — will be included as arrears if payroll generation proceeds, using the contract and rates effective for those work dates.',
                        $this->formatDateRange($range['from'], $range['to']),
                    ),
                    'from_date' => $range['from'],
                    'to_date' => $range['to'],
                    'pay_category' => $payCategory,
                    'action' => 'Review the dates if unexpected. Payroll does not change Crew Assignment history.',
                ];
            }
        }

        foreach ($plan['warnings'] as $_warning) {
            $automaticAdjustments[] = [
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'code' => 'prior_period_fully_excluded',
                'message' => 'All requested prior-period dates were already paid in another payroll and were excluded from this run.',
                'action' => 'No action needed unless the timesheet dates themselves should be corrected.',
            ];
        }
    }

    /**
     * @param  list<int>  $visibleExcludedEmployeeIds
     * @return list<array<string, mixed>>
     */
    private function skippedIssuesForExcluded(int $companyId, array $visibleExcludedEmployeeIds): array
    {
        if ($visibleExcludedEmployeeIds === []) {
            return [];
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $visibleExcludedEmployeeIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => (int) $employee->id,
                'employee_name' => $employee->name,
                'code' => 'explicitly_excluded',
                'message' => 'Explicitly excluded from this payroll run.',
                'action' => 'Include the employee again from the payroll period if they should be paid.',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{from: string, to: string}>
     */
    private function contiguousDateRanges(array $dates): array
    {
        $unique = array_values(array_unique($dates));
        sort($unique);

        if ($unique === []) {
            return [];
        }

        $ranges = [];
        $start = $unique[0];
        $previous = $unique[0];

        for ($index = 1; $index < count($unique); $index++) {
            $current = $unique[$index];
            $expectedNext = CarbonImmutable::parse($previous)->addDay()->toDateString();

            if ($current !== $expectedNext) {
                $ranges[] = ['from' => $start, 'to' => $previous];
                $start = $current;
            }

            $previous = $current;
        }

        $ranges[] = ['from' => $start, 'to' => $previous];

        return $ranges;
    }

    private function formatDateRange(string $from, string $to): string
    {
        $fromDate = CarbonImmutable::parse($from);
        $toDate = CarbonImmutable::parse($to);

        if ($from === $to) {
            return $fromDate->format('j M Y');
        }

        if ($fromDate->year === $toDate->year && $fromDate->month === $toDate->month) {
            return sprintf('%s–%s', $fromDate->format('j'), $toDate->format('j M Y'));
        }

        return sprintf('%s – %s', $fromDate->format('j M Y'), $toDate->format('j M Y'));
    }

    private function actionForCode(string $code): ?string
    {
        return match ($code) {
            'missing_historical_contract', 'missing_crew_contract' => 'Add or correct the employee’s historical Daily Crew contract covering these work dates.',
            'overlapping_historical_contracts' => 'Resolve overlapping Daily Crew contracts so each work date has exactly one covering contract.',
            'missing_historical_salary_revision' => 'Add the missing historical salary revision or rates for these work dates.',
            'missing_basic_daily_rate' => 'Set an active basic daily rate on the contract/revision covering these work dates.',
            'reserved_conflict' => 'Remove these dates from this timesheet or resolve the competing open payroll that already reserved them.',
            'invalid_timesheet_source', 'invalid_source_for_monthly' => 'Correct the timesheet source for this employee’s salary structure.',
            'incomplete_movement_range' => 'Complete the Sign-On / Onsite / Sign-Off movement dates if this employee should be paid for a full voyage.',
            default => null,
        };
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     * @return Collection<int, Employee>
     */
    private function loadEmployees(int $companyId, array $excludedEmployeeIds, ?User $user = null): Collection
    {
        $query = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($query, $user, $companyId);
        }

        if ($excludedEmployeeIds !== []) {
            $query->whereNotIn('employees.id', $excludedEmployeeIds);
        }

        return $query->orderBy('employees.name')->get();
    }

    /**
     * Resolve stored exclusion IDs to current-company employees the actor may see.
     * Phantom, deleted, and foreign-company IDs must not inflate preview counts.
     *
     * @param  list<int>  $excluded
     * @return list<int>
     */
    private function visibleExcludedIds(array $excluded, int $companyId, ?User $user): array
    {
        if ($excluded === []) {
            return [];
        }

        if ($user !== null) {
            return EmployeeVisibilityScope::filterAuthorizedEmployeeIds($user, $companyId, $excluded);
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $excluded)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function emptyReadyPreview(): CrewPayrollGenerationPreview
    {
        return new CrewPayrollGenerationPreview(
            ready: true,
            canGenerate: true,
            readyEmployeeIds: [],
            readyCount: 0,
            missingTimesheetEmployeeIds: [],
            missingTimesheetCount: 0,
            awaitingApprovalEmployeeIds: [],
            awaitingApprovalCount: 0,
            excludedEmployeeIds: [],
            excludedCount: 0,
            blockingIssues: [],
            blockingCount: 0,
            appliedPreparationId: null,
            appliedPreparationVersion: null,
        );
    }
}
