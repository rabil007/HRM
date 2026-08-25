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
            $intent[$field] = self::nullableString($payload[$field] ?? null);
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
