<?php

namespace App\Support\Payroll\CrewTimeline;

final class ApplyCrewTimesheetPreparationResult
{
    /**
     * @param  list<array{employee_id: int, employee_number: string|null, employee_name: string|null, reason: string}>  $skippedEmployees
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $appliedEmployeeCount,
        public readonly int $createdTimesheetCount,
        public readonly int $updatedTimesheetCount,
        public readonly int $skippedEmployeeCount,
        public readonly array $skippedEmployees,
        public readonly array $warnings,
        public readonly bool $idempotent = false,
    ) {}

    /**
     * @return array{
     *     applied_employee_count: int,
     *     created_timesheet_count: int,
     *     updated_timesheet_count: int,
     *     skipped_employee_count: int,
     *     skipped_employees: list<array{employee_id: int, employee_number: string|null, employee_name: string|null, reason: string}>,
     *     warnings: list<string>,
     *     idempotent: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'applied_employee_count' => $this->appliedEmployeeCount,
            'created_timesheet_count' => $this->createdTimesheetCount,
            'updated_timesheet_count' => $this->updatedTimesheetCount,
            'skipped_employee_count' => $this->skippedEmployeeCount,
            'skipped_employees' => $this->skippedEmployees,
            'warnings' => $this->warnings,
            'idempotent' => $this->idempotent,
        ];
    }
}
