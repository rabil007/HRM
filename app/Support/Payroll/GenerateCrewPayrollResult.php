<?php

namespace App\Support\Payroll;

final class GenerateCrewPayrollResult
{
    /**
     * @param  list<array{id: int, name: string, employee_no: string|null}>  $skippedEmployees
     * @param  list<array{employee_id: int, message: string}>  $errors
     */
    public function __construct(
        public readonly int $generatedCount,
        public readonly int $skippedCount,
        public readonly array $skippedEmployees,
        public readonly array $errors,
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
            'errors' => $this->errors,
        ];
    }
}
