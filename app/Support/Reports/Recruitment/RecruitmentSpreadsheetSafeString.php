<?php

namespace App\Support\Reports\Recruitment;

final class RecruitmentSpreadsheetSafeString
{
    /**
     * Guard spreadsheet cells against formula injection (CSV/Excel DDE injection).
     * Any cell beginning with =, +, -, @, \t, or \r is prefixed with a single quote.
     */
    public static function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
