<?php

namespace App\Support\CrewMovements\Historical;

/**
 * Guard spreadsheet string cells against Excel formula injection.
 */
final class HistoricalSpreadsheetSafeString
{
    public static function forExport(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/^[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    public static function isFormulaCellValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return str_starts_with(ltrim($value), '=');
    }
}
