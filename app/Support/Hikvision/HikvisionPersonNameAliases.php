<?php

namespace App\Support\Hikvision;

use App\Models\Employee;
use App\Models\HikvisionPerson;

final class HikvisionPersonNameAliases
{
    /**
     * Safe exact-name aliases for matching unlinked access events to a linked employee.
     *
     * Includes the employee HR name, Hikvision full name, and a conservative trailing-initial
     * strip (e.g. "Mohammed Rabil T" / "Mohammed Rabil T." → "Mohammed Rabil"). Surnames are
     * never stripped. Never uses partial/fuzzy matches.
     *
     * @return list<string>
     */
    public static function forEmployee(Employee $employee): array
    {
        $aliases = [
            trim((string) $employee->name),
            trim((string) ($employee->hikvisionPerson?->full_name ?? '')),
        ];

        $fullName = trim((string) ($employee->hikvisionPerson?->full_name ?? ''));

        if ($fullName !== '') {
            $aliases = [...$aliases, ...self::shortTrailingTokenAliases($fullName)];
        }

        return self::uniqueNonEmpty($aliases);
    }

    /**
     * @return list<string>
     */
    public static function forPerson(HikvisionPerson $person): array
    {
        $fullName = trim((string) ($person->full_name ?? ''));

        if ($fullName === '') {
            return [];
        }

        return self::uniqueNonEmpty([
            $fullName,
            ...self::shortTrailingTokenAliases($fullName),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function shortTrailingTokenAliases(string $fullName): array
    {
        $parts = preg_split('/\s+/u', $fullName) ?: [];

        if (count($parts) <= 1) {
            return [];
        }

        $last = (string) end($parts);

        if (! self::isTrailingInitial($last)) {
            return [];
        }

        $withoutTrailing = trim(implode(' ', array_slice($parts, 0, -1)));

        return $withoutTrailing !== '' ? [$withoutTrailing] : [];
    }

    /**
     * A genuine trailing initial is a single alphabetic character, optionally followed by a period.
     */
    private static function isTrailingInitial(string $token): bool
    {
        return (bool) preg_match('/^\p{L}\.?$/u', $token);
    }

    /**
     * @param  list<string>  $aliases
     * @return list<string>
     */
    private static function uniqueNonEmpty(array $aliases): array
    {
        return array_values(array_unique(array_filter(
            $aliases,
            fn (string $value): bool => $value !== '',
        )));
    }
}
