<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Exceptions\CrewMovementException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Canonical UTC helpers for crew scheduled movement timestamps.
 *
 * Operators enter company-local wall times. Persistence, due selection, lateness,
 * claims, and recovery compare UTC instants. Presenters convert back to company-local.
 *
 * DST policy: nonexistent local walls (spring-forward gaps) and ambiguous local walls
 * (fall-back overlaps) are rejected so operators must pick an unambiguous time.
 */
final class CrewScheduledMovementTimestamp
{
    public static function nowUtc(): Carbon
    {
        return Carbon::now('UTC');
    }

    /**
     * Parse a company-local wall clock into a UTC instant.
     *
     * @throws CrewMovementException
     */
    public static function fromCompanyLocal(string $localWall, string $timezone): Carbon
    {
        $normalized = self::normalizeWall(trim(str_replace('T', ' ', $localWall)));

        if ($normalized === null) {
            throw CrewMovementException::make(
                'A valid scheduled date and time is required (YYYY-MM-DD HH:MM).',
                'invalid_timestamp',
            );
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw CrewMovementException::make(
                'Company timezone is invalid.',
                'invalid_timestamp',
            );
        }

        try {
            $parsed = Carbon::parse($normalized, $timezone);
        } catch (Throwable) {
            throw CrewMovementException::make(
                'Scheduled date and time could not be understood.',
                'invalid_timestamp',
            );
        }

        // Nonexistent wall (spring forward): Carbon adjusts the clock; round-trip fails.
        if ($parsed->copy()->timezone($timezone)->format('Y-m-d H:i:s') !== $normalized) {
            throw CrewMovementException::make(
                'That local date and time does not exist because of a daylight-saving time change. Choose another time.',
                'invalid_timestamp',
            );
        }

        if (self::isAmbiguousLocalWall($normalized, $zone)) {
            throw CrewMovementException::make(
                'That local date and time is ambiguous because of a daylight-saving time change. Choose a time outside the repeated hour.',
                'invalid_timestamp',
            );
        }

        return $parsed->utc();
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

    private static function normalizeWall(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $second = isset($matches[4]) ? (int) $matches[4] : 0;

        return sprintf('%s %s:%s:%02d', $matches[1], $matches[2], $matches[3], $second);
    }

    /**
     * True when the wall clock occurs twice on a fall-back DST day.
     *
     * Uses adjacent DST→STD transitions: if interpreting the wall with both the
     * pre-transition and post-transition offsets yields the same local digits,
     * the wall is ambiguous.
     */
    private static function isAmbiguousLocalWall(string $normalizedWall, DateTimeZone $zone): bool
    {
        $day = substr($normalizedWall, 0, 10);

        try {
            $dayStart = (new DateTimeImmutable($day.' 00:00:00', $zone))->getTimestamp() - 7200;
            $dayEnd = (new DateTimeImmutable($day.' 23:59:59', $zone))->getTimestamp() + 7200;
        } catch (Throwable) {
            return false;
        }

        $transitions = $zone->getTransitions($dayStart, $dayEnd);

        for ($i = 1, $count = count($transitions); $i < $count; $i++) {
            $previous = $transitions[$i - 1];
            $current = $transitions[$i];

            if (($previous['isdst'] ?? false) !== true || ($current['isdst'] ?? true) !== false) {
                continue;
            }

            $dstOffset = (int) ($previous['offset'] ?? 0);
            $stdOffset = (int) ($current['offset'] ?? 0);

            if ($dstOffset <= $stdOffset) {
                continue;
            }

            // Interpret naive wall as if it were UTC, then subtract each offset.
            $asUtcGuess = strtotime($normalizedWall.' UTC');
            if ($asUtcGuess === false) {
                continue;
            }

            $utcIfDst = $asUtcGuess - $dstOffset;
            $utcIfStd = $asUtcGuess - $stdOffset;

            $localFromDst = (new DateTimeImmutable('@'.$utcIfDst))->setTimezone($zone)->format('Y-m-d H:i:s');
            $localFromStd = (new DateTimeImmutable('@'.$utcIfStd))->setTimezone($zone)->format('Y-m-d H:i:s');

            if ($localFromDst === $normalizedWall && $localFromStd === $normalizedWall && $utcIfDst !== $utcIfStd) {
                return true;
            }
        }

        return false;
    }
}
