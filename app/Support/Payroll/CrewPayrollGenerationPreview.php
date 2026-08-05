<?php

namespace App\Support\Payroll;

/**
 * @phpstan-type BlockingIssue array{
 *     employee_id: int|null,
 *     employee_name: string|null,
 *     code: string,
 *     message: string,
 *     work_date?: string|null,
 *     from_date?: string|null,
 *     to_date?: string|null,
 *     pay_category?: string|null,
 *     contract_id?: int|null,
 *     salary_revision_id?: int|null
 * }
 */
final class CrewPayrollGenerationPreview
{
    /**
     * @param  list<int>  $readyEmployeeIds
     * @param  list<int>  $missingTimesheetEmployeeIds
     * @param  list<int>  $awaitingApprovalEmployeeIds
     * @param  list<int>  $excludedEmployeeIds
     * @param  list<BlockingIssue>  $blockingIssues
     */
    public function __construct(
        public readonly bool $ready,
        public readonly bool $canGenerate,
        public readonly array $readyEmployeeIds,
        public readonly int $readyCount,
        public readonly array $missingTimesheetEmployeeIds,
        public readonly int $missingTimesheetCount,
        public readonly array $awaitingApprovalEmployeeIds,
        public readonly int $awaitingApprovalCount,
        public readonly array $excludedEmployeeIds,
        public readonly int $excludedCount,
        public readonly array $blockingIssues,
        public readonly int $blockingCount,
        public readonly ?int $appliedPreparationId,
        public readonly ?int $appliedPreparationVersion,
        public readonly ?string $periodBlockingReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeEmployeeIds = true): array
    {
        $payload = [
            'ready' => $this->ready,
            'can_generate' => $this->canGenerate,
            'ready_count' => $this->readyCount,
            'missing_timesheet_count' => $this->missingTimesheetCount,
            'awaiting_approval_count' => $this->awaitingApprovalCount,
            'excluded_count' => $this->excludedCount,
            'blocking_issues' => array_slice($this->groupedBlockingIssues(), 0, 25),
            'blocking_count' => $this->blockingCount,
            'applied_preparation_id' => $this->appliedPreparationId,
            'applied_preparation_version' => $this->appliedPreparationVersion,
            'period_blocking_reason' => $this->periodBlockingReason,
            'blocking_reason' => $this->periodBlockingReason
                ?? ($this->blockingIssues[0]['message'] ?? null),
            'affected_employee_id' => $this->blockingIssues[0]['employee_id'] ?? null,
        ];

        if ($includeEmployeeIds) {
            $payload['ready_employee_ids'] = $this->readyEmployeeIds;
            $payload['missing_timesheet_employee_ids'] = $this->missingTimesheetEmployeeIds;
            $payload['awaiting_approval_employee_ids'] = $this->awaitingApprovalEmployeeIds;
            $payload['excluded_employee_ids'] = $this->excludedEmployeeIds;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return $this->toArray(includeEmployeeIds: false);
    }

    /**
     * Collapses one-issue-per-work-date entries (e.g. a contract conflict
     * repeated for every blocked calendar day) into a single line per
     * employee/issue type. Without this, an employee blocked on many days
     * would consume the entire display cap and hide every other blocked
     * employee.
     *
     * @return list<BlockingIssue>
     */
    private function groupedBlockingIssues(): array
    {
        $groups = [];

        foreach ($this->blockingIssues as $issue) {
            $workDate = $issue['work_date'] ?? $issue['to_date'] ?? $issue['from_date'] ?? null;
            $template = $issue['message'];

            if ($workDate !== null && str_contains($template, $workDate)) {
                $template = str_replace($workDate, '{date}', $template);
            } else {
                $workDate = null;
            }

            $key = ($issue['employee_id'] ?? 'period').'|'.$issue['code'].'|'.$template;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'issue' => $issue,
                    'template' => $template,
                    'dates' => [],
                ];
            }

            if ($workDate !== null) {
                $groups[$key]['dates'][] = $workDate;
            }
        }

        return array_values(array_map(function (array $group): array {
            $issue = $group['issue'];
            $dates = array_values(array_unique($group['dates']));
            sort($dates);

            if ($dates !== []) {
                $rangeText = count($dates) === 1
                    ? $dates[0]
                    : sprintf('%s – %s (%d days)', $dates[0], $dates[count($dates) - 1], count($dates));

                $issue['message'] = str_replace('{date}', $rangeText, $group['template']);
            }

            return $issue;
        }, $groups));
    }
}
