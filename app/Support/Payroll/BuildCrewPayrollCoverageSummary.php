<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;

/**
 * Lightweight coverage counts for the payroll show page.
 * Full classification and integrity checks run only on generation preview/confirm.
 */
final class BuildCrewPayrollCoverageSummary
{
    public function __construct(
        private readonly ResolveCrewContractForPayrollPeriod $resolveContract,
        private readonly CrewOperationsPayrollGenerationGuard $legacyGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PayrollPeriod $period, int $companyId): array
    {
        if ((int) $period->company_id !== $companyId) {
            abort(404);
        }

        if (! $period->isCrew()) {
            return $this->empty();
        }

        $excluded = array_values(array_unique(array_map(
            intval(...),
            $period->excluded_employee_ids ?? [],
        )));

        if ($period->requiresExclusiveCrewOperationsTimesheets()) {
            return $this->exclusiveSummary($period, $companyId, $excluded);
        }

        return $this->hybridCoverage($period, $companyId, $excluded);
    }

    /**
     * @param  list<int>  $excluded
     * @return array<string, mixed>
     */
    private function exclusiveSummary(PayrollPeriod $period, int $companyId, array $excluded): array
    {
        $employees = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('employees.id', $excluded))
            ->get(['employees.id']);

        $legacy = $this->legacyGuard->validateReadiness($period, $employees, $companyId);

        return [
            'ready' => $legacy['ready'],
            'can_generate' => $legacy['ready'] && $employees->isNotEmpty(),
            'ready_count' => $legacy['ready'] ? $employees->count() : 0,
            'missing_timesheet_count' => 0,
            'awaiting_approval_count' => 0,
            'excluded_count' => count($excluded),
            'blocking_count' => $legacy['ready'] ? 0 : 1,
            'blocking_issues' => $legacy['ready'] ? [] : [[
                'employee_id' => $legacy['affected_employee_id'],
                'employee_name' => null,
                'code' => 'exclusive_crew_operations',
                'message' => (string) ($legacy['blocking_reason'] ?? CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE),
            ]],
            'applied_preparation_id' => $legacy['applied_preparation_id'],
            'applied_preparation_version' => $legacy['applied_preparation_version'],
            'period_blocking_reason' => $legacy['ready'] ? null : $legacy['blocking_reason'],
            'blocking_reason' => $legacy['blocking_reason'],
            'affected_employee_id' => $legacy['affected_employee_id'],
        ];
    }

    /**
     * @param  list<int>  $excluded
     * @return array<string, mixed>
     */
    private function hybridCoverage(PayrollPeriod $period, int $companyId, array $excluded): array
    {
        $applied = CrewTimesheetPreparation::query()
            ->where('company_id', $companyId)
            ->where('payroll_period_id', $period->id)
            ->where('status', CrewTimesheetPreparationStatus::Applied)
            ->get(['id', 'version']);

        $periodBlocking = null;
        $blockingCount = 0;
        $blockingIssues = [];

        if ($applied->count() > 1) {
            $periodBlocking = CrewOperationsPayrollGenerationGuard::MULTIPLE_APPLIED_MESSAGE;
            $blockingCount = 1;
            $blockingIssues[] = [
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'multiple_applied_preparations',
                'message' => $periodBlocking,
            ];
        }

        $preparation = $applied->count() === 1 ? $applied->first() : null;

        if ($preparation !== null && $this->legacyGuard->preparationHasBlockingWarnings($preparation)) {
            $periodBlocking = CrewOperationsPayrollGenerationGuard::BLOCKING_WARNINGS_MESSAGE;
            $blockingCount = 1;
            $blockingIssues = [[
                'employee_id' => null,
                'employee_name' => null,
                'code' => 'applied_preparation_blocking_warnings',
                'message' => $periodBlocking,
            ]];
        }

        $employeeIds = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('employees.id', $excluded))
            ->pluck('employees.id')
            ->map(intval(...))
            ->all();

        if ($employeeIds === []) {
            return [
                'ready' => $blockingCount === 0,
                'can_generate' => false,
                'ready_count' => 0,
                'missing_timesheet_count' => 0,
                'awaiting_approval_count' => 0,
                'excluded_count' => count($excluded),
                'blocking_count' => $blockingCount,
                'blocking_issues' => $blockingIssues,
                'applied_preparation_id' => $preparation?->id,
                'applied_preparation_version' => $preparation?->version,
                'period_blocking_reason' => $periodBlocking,
                'blocking_reason' => $periodBlocking,
                'affected_employee_id' => null,
            ];
        }

        $contracts = $this->resolveContract->resolveMany($period, $employeeIds);
        $timesheetRows = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->get(['employee_id', 'source', 'approval_status']);

        $timesheetsByEmployee = $timesheetRows->keyBy(fn (CrewTimesheet $row) => (int) $row->employee_id);

        $missing = 0;
        $awaiting = 0;
        $ready = 0;

        foreach ($employeeIds as $employeeId) {
            $contract = $contracts->get($employeeId);

            if ($contract === null || $contract->payroll_category !== PayrollCategory::Crew) {
                continue;
            }

            $structure = $contract->resolvedSalaryStructure();
            $timesheet = $timesheetsByEmployee->get($employeeId);

            if ($structure === ContractSalaryStructure::Monthly) {
                if ($timesheet === null) {
                    $ready++;

                    continue;
                }

                if ($timesheet->source === CrewTimesheetSource::CrewOperations) {
                    continue;
                }

                if (($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)
                    !== CrewTimesheetApprovalStatus::Approved) {
                    $awaiting++;

                    continue;
                }

                $ready++;

                continue;
            }

            if ($timesheet === null) {
                $missing++;

                continue;
            }

            if ($timesheet->source === CrewTimesheetSource::CrewOperations) {
                $ready++;

                continue;
            }

            if (($timesheet->approval_status ?? CrewTimesheetApprovalStatus::Draft)
                !== CrewTimesheetApprovalStatus::Approved) {
                $awaiting++;

                continue;
            }

            $ready++;
        }

        return [
            'ready' => $blockingCount === 0,
            'can_generate' => $blockingCount === 0 && $ready > 0,
            'ready_count' => $ready,
            'missing_timesheet_count' => $missing,
            'awaiting_approval_count' => $awaiting,
            'excluded_count' => count($excluded),
            'blocking_count' => $blockingCount,
            'blocking_issues' => $blockingIssues,
            'applied_preparation_id' => $preparation?->id,
            'applied_preparation_version' => $preparation?->version,
            'period_blocking_reason' => $periodBlocking,
            'blocking_reason' => $periodBlocking,
            'affected_employee_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'ready' => true,
            'can_generate' => true,
            'ready_count' => 0,
            'missing_timesheet_count' => 0,
            'awaiting_approval_count' => 0,
            'excluded_count' => 0,
            'blocking_count' => 0,
            'blocking_issues' => [],
            'applied_preparation_id' => null,
            'applied_preparation_version' => null,
            'period_blocking_reason' => null,
            'blocking_reason' => null,
            'affected_employee_id' => null,
        ];
    }
}
