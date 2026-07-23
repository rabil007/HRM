<?php

namespace App\Support\Payroll;

final class CrewPayrollGenerationPreview
{
    /**
     * @param  list<int>  $readyEmployeeIds
     * @param  list<int>  $missingTimesheetEmployeeIds
     * @param  list<int>  $awaitingApprovalEmployeeIds
     * @param  list<int>  $excludedEmployeeIds
     * @param  list<array{employee_id: int|null, employee_name: string|null, code: string, message: string}>  $blockingIssues
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
            'blocking_issues' => array_slice($this->blockingIssues, 0, 25),
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
}
