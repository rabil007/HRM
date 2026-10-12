<?php

namespace App\Support\CrewMovements\Historical;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Canonical temporal overlap rules for historical Crew Assignments.
 *
 * Completed / historical intervals use half-open semantics:
 * touching boundaries (end == start) do not overlap.
 * Null end means an open/current interval extending to infinity.
 *
 * Date-only calendar-day reservation policy (company timezone):
 * - start date reserves from local midnight
 * - when start < end, the end date is an exclusive boundary (transfer/sign-off day is free)
 * - when start == end, the single calendar day is reserved as [start 00:00, start+1 day 00:00)
 *   so nonzero-duration same-day plans are never treated as an empty interval
 * - null end remains open-ended
 *
 * Actual movement timestamps are preferred over date-only bounds whenever available.
 */
final class HistoricalAssignmentIntervalOverlap
{
    public static function completedIntervalsOverlap(
        CarbonInterface $startA,
        CarbonInterface $endA,
        CarbonInterface $startB,
        CarbonInterface $endB,
    ): bool {
        return self::intervalsOverlap($startA, $endA, $startB, $endB);
    }

    public static function intervalsOverlap(
        CarbonInterface $startA,
        ?CarbonInterface $endA,
        CarbonInterface $startB,
        ?CarbonInterface $endB,
    ): bool {
        $farFuture = CarbonImmutable::parse('9999-12-31 23:59:59');

        return $startA->lt($endB ?? $farFuture) && $startB->lt($endA ?? $farFuture);
    }

    public static function overlapsActiveAssignment(
        CarbonInterface $newEnd,
        CarbonInterface $activeStart,
    ): bool {
        return $newEnd->gt($activeStart);
    }

    /**
     * Convert a date-only bound into a half-open instant interval in the given timezone.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable|null}
     */
    public static function calendarDayReservationInterval(
        string $startDate,
        ?string $endDate,
        string $timezone,
    ): array {
        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay();

        if ($endDate === null) {
            return ['start' => $start, 'end' => null];
        }

        $end = CarbonImmutable::parse($endDate, $timezone)->startOfDay();

        // Same calendar day: reserve the full local day so the interval is never empty.
        if ($end->equalTo($start)) {
            return ['start' => $start, 'end' => $start->addDay()];
        }

        // Distinct end date remains exclusive (adjacent transfer day is free).
        return ['start' => $start, 'end' => $end];
    }

    /**
     * Day-grain helper for workbook / planning date strings (YYYY-MM-DD).
     * Applies the calendar-day reservation policy above (UTC parse for string-only callers).
     * Null end means open.
     */
    public static function dateStringsOverlap(
        string $startA,
        ?string $endA,
        string $startB,
        ?string $endB,
    ): bool {
        $left = self::calendarDayReservationInterval($startA, $endA, 'UTC');
        $right = self::calendarDayReservationInterval($startB, $endB, 'UTC');

        return self::intervalsOverlap(
            $left['start'],
            $left['end'],
            $right['start'],
            $right['end'],
        );
    }

    /**
     * Day-grain helper for workbook strings (YYYY-MM-DD).
     */
    public static function completedDateStringsOverlap(
        string $startA,
        string $endA,
        string $startB,
        string $endB,
    ): bool {
        return self::dateStringsOverlap($startA, $endA, $startB, $endB);
    }
}
