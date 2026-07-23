<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

final class BuildCrewPayrollGenerationPreview
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly ValidateCrewTimesheetOperationalIntegrity $validateIntegrity,
        private readonly CrewOperationsPayrollGenerationGuard $legacyGuard,
    ) {}

    /**
     * @param  list<int>  $excludedEmployeeIds
     */
    public function handle(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds = [],
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

        if ($period->requiresExclusiveCrewOperationsTimesheets()) {
            return $this->exclusiveCrewOperationsPreview($period, $companyId, $excludedEmployeeIds);
        }

        return $this->hybridOrManualPreview($period, $companyId, $excludedEmployeeIds);
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     */
    private function exclusiveCrewOperationsPreview(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds,
    ): CrewPayrollGenerationPreview {
        $employees = $this->loadEmployees($companyId, $excludedEmployeeIds);
        $legacy = $this->legacyGuard->validateReadiness($period, $employees, $companyId);

        if (! $legacy['ready']) {
            $blocking = [[
                'employee_id' => $legacy['affected_employee_id'],
                'employee_name' => null,
                'code' => 'exclusive_crew_operations',
                'message' => (string) ($legacy['blocking_reason'] ?? CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE),
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
                excludedEmployeeIds: $excludedEmployeeIds,
                excludedCount: count($excludedEmployeeIds),
                blockingIssues: $blocking,
                blockingCount: 1,
                appliedPreparationId: $legacy['applied_preparation_id'],
                appliedPreparationVersion: $legacy['applied_preparation_version'],
                periodBlockingReason: $legacy['blocking_reason'],
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
            excludedEmployeeIds: $excludedEmployeeIds,
            excludedCount: count($excludedEmployeeIds),
            blockingIssues: [],
            blockingCount: 0,
            appliedPreparationId: $legacy['applied_preparation_id'],
            appliedPreparationVersion: $legacy['applied_preparation_version'],
        );
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     */
    private function hybridOrManualPreview(
        PayrollPeriod $period,
        int $companyId,
        array $excludedEmployeeIds,
    ): CrewPayrollGenerationPreview {
        $allEmployees = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
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
            ->with('preparation')
            ->get()
            ->keyBy(fn (CrewTimesheet $timesheet) => (int) $timesheet->employee_id);

        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->get();

        $blockingIssues = [];
        $readyIds = [];
        $missingIds = [];
        $awaitingIds = [];

        if ($applied->count() > 1) {
            $blockingIssues[] = [
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'multiple_applied_preparations',
                'message' => CrewOperationsPayrollGenerationGuard::MULTIPLE_APPLIED_MESSAGE,
            ];
        }

        /** @var CrewTimesheetPreparation|null $preparation */
        $preparation = $applied->count() === 1 ? $applied->first() : null;

        if ($preparation !== null && $this->legacyGuard->preparationHasBlockingWarnings($preparation)) {
            $blockingIssues[] = [
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'applied_preparation_blocking_warnings',
                'message' => CrewOperationsPayrollGenerationGuard::BLOCKING_WARNINGS_MESSAGE,
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
                        'message' => "{$employee->name} is Monthly Crew but has a Crew Operations timesheet.",
                    ];

                    continue;
                }

                if (! $this->isFallbackApproved($timesheet)) {
                    $awaitingIds[] = $employeeId;

                    continue;
                }

                $readyIds[] = $employeeId;

                continue;
            }

            if ($timesheet === null) {
                $missingIds[] = $employeeId;

                continue;
            }

            $source = $timesheet->resolvedSource();

            if ($source === CrewTimesheetSource::CrewOperations) {
                $linkReason = $this->legacyGuard->dailyTimesheetLinkReason(
                    $employee,
                    $period,
                    $preparation,
                    $companyId,
                    $timesheet,
                );

                if ($linkReason !== null) {
                    $blockingIssues[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->name,
                        'code' => 'crew_operations_linkage',
                        'message' => $linkReason,
                    ];

                    continue;
                }

                $integrity = $this->validateIntegrity->handle($timesheet, $employee);

                if ($integrity !== null) {
                    $blockingIssues[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->name,
                        'code' => 'invalid_approved_timesheet',
                        'message' => $integrity,
                    ];

                    continue;
                }

                $readyIds[] = $employeeId;

                continue;
            }

            if (! in_array($source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true)) {
                // #region agent log
                file_put_contents(base_path('.cursor/debug-7d7dd1.log'), json_encode(['sessionId' => '7d7dd1', 'hypothesisId' => 'B', 'location' => 'BuildCrewPayrollGenerationPreview.php:invalid_timesheet_source', 'message' => 'invalid timesheet source branch', 'data' => ['employee_id' => $employeeId, 'timesheet_id' => $timesheet->id, 'source_value' => $timesheet->source instanceof CrewTimesheetSource ? $timesheet->source->value : $timesheet->source, 'resolved_source' => $source->value, 'source_is_null' => $timesheet->source === null, 'approval' => $timesheet->approval_status?->value, 'runId' => 'post-fix'], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
                // #endregion
                $blockingIssues[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'code' => 'invalid_timesheet_source',
                    'message' => "{$employee->name} timesheet source must be Manual, Import, or Crew Operations.",
                ];

                continue;
            }

            if (! $this->isFallbackApproved($timesheet)) {
                $awaitingIds[] = $employeeId;

                continue;
            }

            $integrity = $this->validateIntegrity->handle($timesheet, $employee);

            if ($integrity !== null) {
                $blockingIssues[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->name,
                    'code' => 'invalid_approved_timesheet',
                    'message' => $integrity,
                ];

                continue;
            }

            $readyIds[] = $employeeId;
        }

        $blockingCount = count($blockingIssues);
        $readyCount = count($readyIds);
        $periodBlocking = null;

        if ($blockingCount > 0 && ($blockingIssues[0]['employee_id'] ?? null) === null) {
            $periodBlocking = $blockingIssues[0]['message'];
        }

        $ready = $blockingCount === 0;

        // #region agent log
        file_put_contents(base_path('.cursor/debug-7d7dd1.log'), json_encode(['sessionId' => '7d7dd1', 'hypothesisId' => 'A', 'location' => 'BuildCrewPayrollGenerationPreview.php:preview_result', 'message' => 'hybrid/manual preview result', 'data' => ['period_id' => $period->id, 'ready_count' => $readyCount, 'blocking_count' => $blockingCount, 'can_generate' => $ready && $readyCount > 0, 'blocking_codes' => array_column($blockingIssues, 'code'), 'runId' => 'post-fix'], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        return new CrewPayrollGenerationPreview(
            ready: $ready,
            canGenerate: $ready && $readyCount > 0,
            readyEmployeeIds: $readyIds,
            readyCount: $readyCount,
            missingTimesheetEmployeeIds: $missingIds,
            missingTimesheetCount: count($missingIds),
            awaitingApprovalEmployeeIds: $awaitingIds,
            awaitingApprovalCount: count($awaitingIds),
            excludedEmployeeIds: $excludedEmployeeIds,
            excludedCount: count($excludedEmployeeIds),
            blockingIssues: $blockingIssues,
            blockingCount: $blockingCount,
            appliedPreparationId: $preparation?->id,
            appliedPreparationVersion: $preparation?->version,
            periodBlockingReason: $periodBlocking,
        );
    }

    private function isFallbackApproved(CrewTimesheet $timesheet): bool
    {
        return ($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)
            === CrewTimesheetApprovalStatus::Approved;
    }

    /**
     * @param  list<int>  $excludedEmployeeIds
     * @return Collection<int, Employee>
     */
    private function loadEmployees(int $companyId, array $excludedEmployeeIds): Collection
    {
        $query = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        if ($excludedEmployeeIds !== []) {
            $query->whereNotIn('employees.id', $excludedEmployeeIds);
        }

        return $query->orderBy('employees.name')->get();
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
