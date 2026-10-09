<?php

namespace App\Support\CrewMovements\Scheduling;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Canonical UTC helpers for crew scheduled movement timestamps.
 *
 * Operators enter company-local wall times. Persistence, due selection, lateness,
 * claims, and recovery compare UTC instants. Presenters convert back to company-local.
 */
final class CrewScheduledMovementTimestamp
{
    public static function nowUtc(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Parse a company-local wall clock into a UTC instant.
     */
    public static function fromCompanyLocal(string $localWall, string $timezone): Carbon
    {
        return Carbon::parse($localWall, $timezone)->utc();
    }

    /**
     * Format a UTC instant as a company-local wall clock string.
     */
    public static function toCompanyLocalString(
        CarbonInterface $utcInstant,
        string $timezone,
        string $format = 'Y-m-d H:i:s',
    ): string {
        return $utcInstant->copy()->timezone($timezone)->format($format);
    }

    /**
     * Persistable UTC naive datetime string (MySQL DATETIME / SQLite).
     */
    public static function storeUtc(CarbonInterface $instant): string
    {
        return $instant->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * SQL binding for comparing against UTC DATETIME columns.
     */
    public static function sqlUtc(CarbonInterface $instant): string
    {
        return self::storeUtc($instant);
    }
}
