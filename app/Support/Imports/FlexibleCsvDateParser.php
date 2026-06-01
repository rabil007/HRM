<?php

namespace App\Support\Imports;

use Carbon\CarbonImmutable;

final class FlexibleCsvDateParser
{
    /**
     * Parse common spreadsheet / regional date strings for CSV imports.
     * Prefers day-first (d/m) formats, then US (m/d), then ISO.
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            try {
                return CarbonImmutable::parse($trimmed)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        foreach (self::dayFirstFormats() as $format) {
            $parsed = self::createFromFormat($format, $trimmed);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach (self::monthFirstFormats() as $format) {
            $parsed = self::createFromFormat($format, $trimmed);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        try {
            return CarbonImmutable::parse($trimmed)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function dayFirstFormats(): array
    {
        return [
            'd/m/Y',
            'd/m/y',
            'j/n/Y',
            'j/n/y',
            'd-m-Y',
            'd-m-y',
            'd.m.Y',
            'd.m.y',
        ];
    }

    /**
     * @return list<string>
     */
    private static function monthFirstFormats(): array
    {
        return [
            'm/d/Y',
            'm/d/y',
            'n/j/Y',
            'n/j/y',
            'm-d-Y',
            'm-d-y',
        ];
    }

    private static function createFromFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
        } catch (\Throwable) {
            return null;
        }

        if ($parsed === false) {
            return null;
        }

        $errors = CarbonImmutable::getLastErrors();

        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return self::normalizeTwoDigitYear($parsed->startOfDay());
    }

    private static function normalizeTwoDigitYear(CarbonImmutable $date): CarbonImmutable
    {
        if ($date->year >= 100) {
            return $date;
        }

        return $date->year(2000 + $date->year);
    }
}
