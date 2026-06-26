<?php

namespace App\Support\Payroll;

final class EmployeeLeavePeriodSummary
{
    /**
     * @param  list<array{leave_type_id: int, code: string, name: string, color: string|null, days: float}>  $leaveUsage
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
     * @return list<array{leave_type_id: int, code: string, name: string, color: string|null, days: float}>
     */
    public function toLeaveUsageArray(): array
    {
        return $this->leaveUsage;
    }
}
