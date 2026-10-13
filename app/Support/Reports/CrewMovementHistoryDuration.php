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

    /**
     * Inclusive company-local calendar dates touched by a service window.
     *
     * Same-day service counts as 1. Dates after company-local today are not
     * counted as worked. A window that starts after today counts as 0.
     * Returns null when a bound is missing or the end date is before the start.
     */
    public static function inclusiveCalendarDays(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        string $timezone,
        ?CarbonInterface $today = null,
    ): ?int {
        $dates = self::inclusiveCalendarDates($start, $end, $timezone, $today);

        return $dates === null ? null : count($dates);
    }

    /**
     * Company-local dates in an inclusive service window, clamped to today.
     * Null when the window is invalid. Empty when the whole window is in the future.
     *
     * @return list<string>|null
     */
    public static function inclusiveCalendarDates(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        string $timezone,
        ?CarbonInterface $today = null,
    ): ?array {
        if ($start === null || $end === null) {
            return null;
        }

        $from = $start->copy()->timezone($timezone)->startOfDay();
        $to = $end->copy()->timezone($timezone)->startOfDay();

        if ($to->lt($from)) {
            return null;
        }

        $todayStart = ($today ?? now($timezone))->copy()->timezone($timezone)->startOfDay();

        if ($from->gt($todayStart)) {
            return [];
        }

        if ($to->gt($todayStart)) {
            $to = $todayStart;
        }

        $dates = [];

        for ($date = $from->copy(); $date->lte($to); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * Elapsed-day label. Zero means the window started on the end date.
     */
    public static function label(?int $days): string
    {
        return match ($days) {
            null => 'Not recorded',
            0 => 'Started today',
            1 => '1 day',
            default => "{$days} days",
        };
    }

    /**
     * Inclusive service-day label. Zero is a counted total, not "started today".
     */
    public static function serviceDayLabel(?int $days): string
    {
        return match ($days) {
            null => 'Not recorded',
            0 => '0 days',
            1 => '1 day',
            default => "{$days} days",
        };
    }

    public static function futureActualLabel(): string
    {
        return 'Future actual';
    }
}
