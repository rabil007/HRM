<?php

namespace App\Support\Payroll;

final class EmployeeLeavePeriodSummary
{
    /**
     * @param  list<array{leave_type_id: int, code: string, name: string, color: string|null, days: float, payroll_treatment?: string}>  $leaveUsage
     */
    public function __construct(
        public readonly float $totalLeaveDays,
        public readonly array $leaveUsage,
    ) {}

    public function hasLeaveUsage(): bool
    {
        return $this->totalLeaveDays > 0;
    }

    /**
     * @return list<array{leave_type_id: int, code: string, name: string, color: string|null, days: float, payroll_treatment?: string}>
     */
    public function toLeaveUsageArray(): array
    {
        return $this->leaveUsage;
    }
}
