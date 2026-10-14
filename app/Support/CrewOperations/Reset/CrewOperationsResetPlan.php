<?php

namespace App\Support\CrewOperations\Reset;

final class CrewOperationsResetPlan
{
    /**
     * @param  list<array{entity: string, delete: int, preserve: int, notes: string}>  $rows
     * @param  list<array{company_id: int, company_name: string, year: int, current_last_number: int, next_assignment_no: string}>  $sequenceRows
     * @param  list<string>  $blockingIssues
     * @param  list<string>  $warnings
     * @param  list<int>  $assignmentIds
     * @param  list<int>  $phaseIds
     * @param  list<int>  $crewTimesheetIds
     * @param  list<int>  $eligiblePayrollRecordIds
     * @param  list<int>  $companyIds
     */
    public function __construct(
        public readonly ?int $companyId,
        public readonly array $rows,
        public readonly array $sequenceRows,
        public readonly array $blockingIssues,
        public readonly array $warnings,
        public readonly array $assignmentIds,
        public readonly array $phaseIds,
        public readonly array $crewTimesheetIds,
        public readonly array $eligiblePayrollRecordIds,
        public readonly array $companyIds,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockingIssues !== [];
    }

    public function totalDeletes(): int
    {
        return array_sum(array_column($this->rows, 'delete'));
    }
}
