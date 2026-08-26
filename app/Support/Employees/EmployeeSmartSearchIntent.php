<?php

namespace App\Support\Employees;

use App\Exceptions\EmployeeSmartSearchUnavailableException;

final class EmployeeSmartSearchIntent
{
    /** @var list<string> */
    public const FIELDS = [
        'status',
        'department',
        'position',
        'nationality',
        'rank',
        'crew_status',
        'emirates_id_presence',
    ];

    /** @var list<string> */
    public const EMIRATES_ID_PRESENCE_VALUES = [
        EmployeeDirectoryFilters::EMIRATES_ID_PRESENCE_MISSING,
        EmployeeDirectoryFilters::EMIRATES_ID_PRESENCE_PRESENT,
    ];

    /**
     * Normalize a decoded provider payload to the trusted filter-intent schema.
     *
     * Extra model fields are discarded. Empty, list, or unstructured payloads fail closed.
     *
     * @param  array<mixed>  $payload
     * @return array{
     *     status: string|null,
     *     department: string|null,
     *     position: string|null,
     *     nationality: string|null,
     *     rank: string|null,
     *     crew_status: string|null,
     *     emirates_id_presence: string|null,
     *     unsupported_terms: list<string>
     * }
     */
    public static function fromDecoded(array $payload): array
    {
        if ($payload === [] || array_is_list($payload)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        if (! array_key_exists('unsupported_terms', $payload)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        $hasFilterField = false;

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $hasFilterField = true;
                break;
            }
        }

        if (! $hasFilterField) {
            $keys = array_keys($payload);
            sort($keys);

            if ($keys !== ['unsupported_terms']) {
                throw EmployeeSmartSearchUnavailableException::providerFailed();
            }
        }

        $intent = [];

        foreach (self::FIELDS as $field) {
            $intent[$field] = $field === 'emirates_id_presence'
                ? self::emiratesIdPresence($payload[$field] ?? null)
                : self::nullableString($payload[$field] ?? null);
        }

        $intent['unsupported_terms'] = self::stringList($payload['unsupported_terms']);

        return $intent;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function emiratesIdPresence(mixed $value): ?string
    {
        $normalized = self::nullableString($value);

        if ($normalized === null) {
            return null;
        }

        if (! in_array($normalized, self::EMIRATES_ID_PRESENCE_VALUES, true)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        $terms = [];

        foreach ($value as $term) {
            if (! is_string($term)) {
                throw EmployeeSmartSearchUnavailableException::providerFailed();
            }

            $trimmed = trim($term);

            if ($trimmed !== '') {
                $terms[] = $trimmed;
            }
        }

        return array_values(array_unique($terms));
    }
}
