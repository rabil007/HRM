<?php

namespace App\Support\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CountWorkingDaysInRange
{
    /**
     * @param  list<int>  $workingDays  ISO weekday numbers (1=Mon … 7=Sun)
     */
    public function count(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        array $workingDays,
    ): int {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $allowed = $workingDays !== [] ? $workingDays : [1, 2, 3, 4, 5];
        $count = 0;

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $allowed, true)) {
                $count++;
            }
        }

        return $count;
    }
}
