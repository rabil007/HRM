<?php

namespace App\Support\Reports;

use Carbon\CarbonInterface;

final class CrewMovementHistoryDuration
{
    public static function elapsedDays(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        string $timezone,
    ): ?int {
        if ($start === null || $end === null) {
            return null;
        }

        $from = $start->copy()->timezone($timezone)->startOfDay();
        $to = $end->copy()->timezone($timezone)->startOfDay();

        if ($to->isBefore($from)) {
            return null;
        }

        return (int) $from->diffInDays($to);
    }

    public static function label(?int $days): string
    {
        return match ($days) {
            null => 'Not recorded',
            0 => 'Started today',
            1 => '1 day',
            default => "{$days} days",
        };
    }
}
