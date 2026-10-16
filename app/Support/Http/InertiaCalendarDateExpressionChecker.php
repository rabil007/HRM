<?php

namespace App\Support\Http;

/**
 * Detects Eloquent calendar-date casts passed into Inertia/JSON payloads
 * without an explicit Y-m-d serialization step.
 */
final class InertiaCalendarDateExpressionChecker
{
    public static function expressionReadsCalendarDateKey(string $expression, string $key): bool
    {
        return (bool) preg_match(
            '/\$[A-Za-z0-9_]+(?:(?:\?->|->)[A-Za-z0-9_]+)*(?:\?->|->)'.preg_quote($key, '/').'\b/',
            $expression,
        );
    }

    public static function expressionAlreadySerializesCalendarDate(string $expression): bool
    {
        return str_contains($expression, 'toDateString')
            || str_contains($expression, "format('Y-m-d')")
            || str_contains($expression, 'format("Y-m-d")')
            || str_contains($expression, 'PlatformDateFormatter')
            || (bool) preg_match('/\$dates->date\(|formatCvDate\(/', $expression);
    }

    public static function expressionIsNonPayloadNoise(string $expression): bool
    {
        return str_contains($expression, '(bool)')
            || str_contains($expression, 'orderBy')
            || str_contains($expression, '$request')
            || str_contains($expression, 'old(')
            || str_contains($expression, 'validated(');
    }

    public static function expressionIsEloquentWrite(string $expression): bool
    {
        return (bool) preg_match('/::create\(|->update\(|->forceFill\(|->fill\(/', $expression);
    }

    /**
     * @return bool true when the assignment is unsafe for Inertia JSON
     */
    public static function isUnsafeCalendarDateAssignment(string $key, string $expression, array $calendarDateKeys): bool
    {
        if (! in_array($key, $calendarDateKeys, true)) {
            return false;
        }

        if (! self::expressionReadsCalendarDateKey($expression, $key)) {
            return false;
        }

        if (self::expressionAlreadySerializesCalendarDate($expression)) {
            return false;
        }

        if (self::expressionIsNonPayloadNoise($expression)) {
            return false;
        }

        if (self::expressionIsEloquentWrite($expression)) {
            return false;
        }

        return true;
    }
}
