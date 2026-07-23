<?php

namespace App\Support\Payroll;

final class GeneratePayrollResult
{
    /**
     * @param  list<array{id: int, name: string, employee_no: string|null, reason?: string}>  $skippedEmployees
     * @param  list<array{
     *     employee_id: int,
     *     employee_name: string,
     *     employee_no: string|null,
     *     message: string,
     *     field: string|null,
     *     field_label: string|null,
     *     employee_url: string
     * }>  $errors
     * @param  array<string, mixed>|null  $preview
     */
    public function __construct(
        public readonly int $generatedCount,
        public readonly int $skippedCount,
        public readonly array $skippedEmployees,
        public readonly array $errors,
        public readonly int $skippedMissingTimesheetCount = 0,
        public readonly int $skippedAwaitingApprovalCount = 0,
        public readonly int $skippedExcludedCount = 0,
        public readonly ?array $preview = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSessionArray(): array
    {
        return [
            'generated_count' => $this->generatedCount,
            'skipped_count' => $this->skippedCount,
            'skipped_employees' => $this->skippedEmployees,
            'skipped_missing_timesheet_count' => $this->skippedMissingTimesheetCount,
            'skipped_awaiting_approval_count' => $this->skippedAwaitingApprovalCount,
            'skipped_excluded_count' => $this->skippedExcludedCount,
            'errors' => $this->errors,
            'preview' => $this->preview,
        ];
    }
}
