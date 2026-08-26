<?php

use App\Services\EmployeeSmartSearchInterpreter;
use App\Support\Employees\EmployeeSmartSearchConceptRegistry;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

test('structured output schema lists every property as required for OpenAI strict mode', function () {
    $schema = (new ObjectSchema(
        app(EmployeeSmartSearchInterpreter::class)->schema(new JsonSchemaTypeFactory),
    ))->toSchema();

    $propertyKeys = array_keys($schema['properties'] ?? []);

    expect($schema['required'] ?? [])->toEqualCanonicalizing($propertyKeys)
        ->and($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and($propertyKeys)->toEqualCanonicalizing(['criteria', 'ambiguous_terms', 'unsupported_terms']);

    $criterion = $schema['properties']['criteria']['items'] ?? [];
    $criterionProperties = array_keys($criterion['properties'] ?? []);

    expect($criterion['type'] ?? null)->toBe('object')
        ->and($criterion['additionalProperties'] ?? null)->toBeFalse()
        ->and($criterion['required'] ?? [])->toEqualCanonicalizing($criterionProperties)
        ->and($criterionProperties)->toEqualCanonicalizing(['concept', 'operator', 'value'])
        ->and($criterion['properties']['concept']['enum'] ?? [])->toEqualCanonicalizing(EmployeeSmartSearchConceptRegistry::keys())
        ->and($criterion['properties']['operator']['enum'] ?? [])->toEqualCanonicalizing(EmployeeSmartSearchConceptRegistry::OPERATORS);

    $valueSchema = $criterion['properties']['value'] ?? [];
    $valueAllowsNull = ($valueSchema['type'] ?? null) === ['string', 'null']
        || in_array('null', (array) ($valueSchema['type'] ?? []), true)
        || (($valueSchema['anyOf'] ?? null) !== null && collect($valueSchema['anyOf'])->contains(fn ($item): bool => ($item['type'] ?? null) === 'null'))
        || array_key_exists('enum', $valueSchema) && in_array(null, $valueSchema['enum'], true);

    expect($valueAllowsNull)->toBeTrue();
});
