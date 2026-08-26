<?php

namespace App\Support\Employees;

use Illuminate\Database\Eloquent\Builder;

final class EmployeeDirectoryCompleteness
{
    public const MISSING_QUERY_KEY = 'missing_fields';

    public const PRESENT_QUERY_KEY = 'present_fields';

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return EmployeeSmartSearchConceptRegistry::presenceKeys();
    }

    public static function isAllowedKey(string $key): bool
    {
        return in_array($key, self::allowedKeys(), true);
    }

    /**
     * Parse a CSV of semantic completeness keys.
     *
     * Unknown keys are retained so directory queries can fail closed without
     * broadening results. Saved Views reject unknown keys separately.
     *
     * @return array{keys: list<string>, unknown: list<string>, valid: bool}
     */
    public static function parse(mixed $value): array
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return ['keys' => [], 'unknown' => [], 'valid' => true];
        }

        $parts = preg_split('/\s*,\s*/', trim((string) $value)) ?: [];
        $keys = [];
        $unknown = [];

        foreach ($parts as $part) {
            $key = strtolower(trim($part));

            if ($key === '') {
                continue;
            }

            if (! self::isAllowedKey($key)) {
                $unknown[] = $key;

                continue;
            }

            $keys[] = $key;
        }

        $keys = self::canonicalize($keys);

        return [
            'keys' => $keys,
            'unknown' => array_values(array_unique($unknown)),
            'valid' => $unknown === [],
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function canonicalize(array $keys): array
    {
        $allowed = self::allowedKeys();
        $filtered = array_values(array_intersect($allowed, array_values(array_unique($keys))));

        return $filtered;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function toCsv(array $keys): string
    {
        return implode(',', self::canonicalize($keys));
    }

    /**
     * @param  list<string>  $keys
     */
    public static function applyMissing(Builder $query, array $keys): void
    {
        self::apply($query, $keys, missing: true);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function applyPresent(Builder $query, array $keys): void
    {
        self::apply($query, $keys, missing: false);
    }

    /**
     * @param  list<string>  $keys
     */
    private static function apply(Builder $query, array $keys, bool $missing): void
    {
        $parsedUnknown = array_values(array_filter(
            $keys,
            fn (string $key): bool => ! self::isAllowedKey($key),
        ));

        if ($parsedUnknown !== []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach (self::canonicalize($keys) as $concept) {
            $definition = EmployeeSmartSearchConceptRegistry::definition($concept);

            if ($definition === null || $definition['presence'] === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            match ($definition['presence']) {
                EmployeeSmartSearchConceptRegistry::PRESENCE_STRING => self::applyString(
                    $query,
                    (string) $definition['column'],
                    $missing,
                ),
                EmployeeSmartSearchConceptRegistry::PRESENCE_FOREIGN_KEY,
                EmployeeSmartSearchConceptRegistry::PRESENCE_DATE => self::applyNullable(
                    $query,
                    (string) $definition['column'],
                    $missing,
                ),
                EmployeeSmartSearchConceptRegistry::PRESENCE_COMPOSITE_EMAIL => self::applyCompositeEmail(
                    $query,
                    $missing,
                ),
                default => $query->whereRaw('1 = 0'),
            };
        }
    }

    private static function applyString(Builder $query, string $column, bool $missing): void
    {
        $expression = 'TRIM(COALESCE('.$query->getModel()->qualifyColumn($column).", ''))";

        if ($missing) {
            $query->whereRaw("{$expression} = ''");

            return;
        }

        $query->whereRaw("{$expression} <> ''");
    }

    private static function applyNullable(Builder $query, string $column, bool $missing): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        if ($missing) {
            $query->whereNull($qualified);

            return;
        }

        $query->whereNotNull($qualified);
    }

    private static function applyCompositeEmail(Builder $query, bool $missing): void
    {
        if ($missing) {
            self::applyString($query, 'work_email', true);
            self::applyString($query, 'personal_email', true);

            return;
        }

        $query->where(function (Builder $inner): void {
            self::applyString($inner, 'work_email', false);
            $inner->orWhere(function (Builder $or): void {
                self::applyString($or, 'personal_email', false);
            });
        });
    }
}
