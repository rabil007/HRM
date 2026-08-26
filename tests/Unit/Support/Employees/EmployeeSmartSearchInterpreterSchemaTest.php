<?php

use App\Services\EmployeeSmartSearchInterpreter;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

test('structured output schema lists every property as required for OpenAI strict mode', function () {
    $schema = (new ObjectSchema(
        app(EmployeeSmartSearchInterpreter::class)->schema(new JsonSchemaTypeFactory),
    ))->toSchema();

    $propertyKeys = array_keys($schema['properties'] ?? []);

    expect($schema['required'] ?? [])->toEqualCanonicalizing($propertyKeys)
        ->and($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and(in_array(null, $schema['properties']['status']['enum'] ?? [], true))->toBeTrue()
        ->and(in_array(null, $schema['properties']['crew_status']['enum'] ?? [], true))->toBeTrue()
        ->and(in_array(null, $schema['properties']['emirates_id_presence']['enum'] ?? [], true))->toBeTrue();
});
