<?php

namespace App\Support\Employees;

final class DraftEmployeeNumber
{
    /**
     * Provisional numbers created by CreateEmployeeFromName before an official
     * employee number is assigned (e.g. DRAFT-FEIXOIU9).
     */
    public static function isDraft(mixed $value): bool
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        return (bool) preg_match('/^DRAFT-[A-Z0-9]+$/i', trim((string) $value));
    }
}
