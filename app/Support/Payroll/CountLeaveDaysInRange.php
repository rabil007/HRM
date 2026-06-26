<?php

namespace App\Support\Payroll;

use App\Support\Attendance\CalculateLeaveRequestDays;

final class CountLeaveDaysInRange
{
    public function __construct(
        private readonly CalculateLeaveRequestDays $calculateDays,
    ) {}

    public function count(string $startDate, string $endDate, string $rangeStart, string $rangeEnd): float
    {
        $clippedStart = max($startDate, $rangeStart);
        $clippedEnd = min($endDate, $rangeEnd);

        if ($clippedStart > $clippedEnd) {
            return 0.0;
        }

        return ($this->calculateDays)($clippedStart, $clippedEnd);
    }
}
