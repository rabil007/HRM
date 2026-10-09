<?php

namespace App\Support\CrewMovements\Scheduling;

use Carbon\CarbonInterface;

/**
 * Automatic execution timestamp policy.
 *
 * - Timely runs use the system execution instant as the operational occurrence.
 * - Runs delayed beyond the documented tolerance are not silently backdated;
 *   they are marked Needs Attention for operator recovery.
 */
final class CrewScheduledMovementLatenessPolicy
{
    /** Maximum delay after scheduled_at before auto-execution is refused. */
    public const TOLERANCE_SECONDS = 900;

    public static function isWithinTolerance(
        CarbonInterface $scheduledAt,
        CarbonInterface $now,
    ): bool {
        return $now->lessThanOrEqualTo(
            $scheduledAt->copy()->addSeconds(self::TOLERANCE_SECONDS),
        );
    }

    public static function toleranceMinutes(): int
    {
        return (int) (self::TOLERANCE_SECONDS / 60);
    }
}
