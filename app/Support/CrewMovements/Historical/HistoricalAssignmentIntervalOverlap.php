<?php

namespace App\Support\CrewMovements\Historical;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Canonical temporal overlap rules for historical Crew Assignments.
 *
 * Completed / historical intervals use half-open semantics matching
 * HistoricalCrewAssignmentValidator: touching boundaries (end == start) do not overlap.
 */
final class HistoricalAssignmentIntervalOverlap
{
    public static function completedIntervalsOverlap(
        CarbonInterface $startA,
        CarbonInterface $endA,
        CarbonInterface $startB,
        CarbonInterface $endB,
    ): bool {
        return $startA->lt($endB) && $startB->lt($endA);
    }

    public static function overlapsActiveAssignment(
        CarbonInterface $newEnd,
        CarbonInterface $activeStart,
    ): bool {
        return $newEnd->gt($activeStart);
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
        return self::completedIntervalsOverlap(
            CarbonImmutable::parse($startA)->startOfDay(),
            CarbonImmutable::parse($endA)->startOfDay(),
            CarbonImmutable::parse($startB)->startOfDay(),
            CarbonImmutable::parse($endB)->startOfDay(),
        );
    }
}
