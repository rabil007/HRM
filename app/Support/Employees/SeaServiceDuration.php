<?php

namespace App\Support\Employees;

use Carbon\Carbon;
use InvalidArgumentException;

final class SeaServiceDuration
{
    /**
     * @return array{months: int, days: int}
     */
    public static function fromDates(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('End date must be on or after start date.');
        }

        $diff = $start->diff($end);
        $inclusiveDays = (int) $start->diffInDays($end) + 1;

        return [
            'months' => ($diff->y * 12) + $diff->m,
            'days' => $inclusiveDays,
        ];
    }
}
