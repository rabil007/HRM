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
     * Day-grain helper for workbook strings (YYYY-MM-DD).
     * Null end means open.
     */
    public static function dateStringsOverlap(
        string $startA,
        ?string $endA,
        string $startB,
        ?string $endB,
    ): bool {
        return self::intervalsOverlap(
            CarbonImmutable::parse($startA)->startOfDay(),
            $endA !== null ? CarbonImmutable::parse($endA)->startOfDay() : null,
            CarbonImmutable::parse($startB)->startOfDay(),
            $endB !== null ? CarbonImmutable::parse($endB)->startOfDay() : null,
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
