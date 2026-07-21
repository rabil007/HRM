<?php

namespace App\Support\Payroll\CrewTimeline;

use Carbon\CarbonInterface;

/**
 * Decides whether two crew phase timestamp intervals genuinely overlap.
 *
 * Crew movement phases are half-open instants `[start, end)`. Movement actions
 * close the current phase and open the next at the exact same `occurred_at`
 * timestamp, so `left.end == right.start` is a valid handoff, not an overlap.
 * A genuine overlap requires a positive-duration intersection:
 *
 *     left.start < right.end  AND  right.start < left.end
 */
final class CrewPhaseIntervalOverlapDetector
{
    public function overlaps(
        CarbonInterface $leftStart,
        CarbonInterface $leftEnd,
        CarbonInterface $rightStart,
        CarbonInterface $rightEnd,
    ): bool {
        return $leftStart->lt($rightEnd) && $rightStart->lt($leftEnd);
    }
}
