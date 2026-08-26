<?php

namespace App\Support\Employees;

use App\Exceptions\EmployeeSmartSearchUnavailableException;

final class EmployeeSmartSearchIntent
{
    /**
     * Normalize a decoded provider payload to the trusted criteria schema.
     *
     * Extra model fields are discarded. Missing required properties, empty,
     * list, or unstructured payloads fail closed.
     *
     * @param  array<mixed>  $payload
     * @return array{
     *     criteria: list<array{concept: string, operator: string, value: string|null}>,
     *     ambiguous_terms: list<string>,
     *     unsupported_terms: list<string>
     * }
     */
    public static function fromDecoded(array $payload): array
    {
        if ($payload === [] || array_is_list($payload)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        foreach (['criteria', 'ambiguous_terms', 'unsupported_terms'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw EmployeeSmartSearchUnavailableException::providerFailed();
            }
        }

        return [
            'criteria' => self::criteria($payload['criteria']),
            'ambiguous_terms' => self::stringList($payload['ambiguous_terms']),
            'unsupported_terms' => self::stringList($payload['unsupported_terms']),
        ];
    }

    /**
     * @return list<array{concept: string, operator: string, value: string|null}>
     */
    private static function criteria(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && ! array_is_list($value))) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        $criteria = [];

        foreach ($value as $item) {
            $criteria[] = self::criterion($item);
        }

        return self::deduplicate($criteria);
    }

    /**
     * @return array{concept: string, operator: string, value: string|null}
     */
    private static function criterion(mixed $item): array
    {
        if (! is_array($item) || $item === [] || array_is_list($item)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        foreach (['concept', 'operator', 'value'] as $required) {
            if (! array_key_exists($required, $item)) {
                throw EmployeeSmartSearchUnavailableException::providerFailed();
            }
        }

        $concept = self::requiredToken($item['concept']);
        $operator = self::requiredToken($item['operator']);
        $value = self::nullableString($item['value']);

        if (! EmployeeSmartSearchConceptRegistry::has($concept)
            || ! EmployeeSmartSearchConceptRegistry::allows($concept, $operator)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        if (in_array($operator, [
            EmployeeSmartSearchConceptRegistry::OPERATOR_MISSING,
            EmployeeSmartSearchConceptRegistry::OPERATOR_PRESENT,
        ], true)) {
            $value = null;
        }

        return [
            'concept' => $concept,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param  list<array{concept: string, operator: string, value: string|null}>  $criteria
     * @return list<array{concept: string, operator: string, value: string|null}>
     */
    private static function deduplicate(array $criteria): array
    {
        $unique = [];
        $seen = [];

        foreach ($criteria as $criterion) {
            $signature = $criterion['concept']."\0".$criterion['operator']."\0".($criterion['value'] ?? '');

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $unique[] = $criterion;
        }

        return $unique;
    }

    private static function requiredToken(mixed $value): string
    {
        if (! is_string($value)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        $trimmed = strtolower(trim($value));

        if ($trimmed === '') {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        return $trimmed;
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
