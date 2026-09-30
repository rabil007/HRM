<?php

use App\Services\AiProviderConnectionTester;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

test('connection tester schema lists every property as required for OpenRouter strict mode', function () {
    $schema = (new ObjectSchema(
        app(AiProviderConnectionTester::class)->schema(new JsonSchemaTypeFactory),
    ))->toSchema();

    $propertyKeys = array_keys($schema['properties'] ?? []);

    expect($schema['required'] ?? [])->toEqualCanonicalizing($propertyKeys)
        ->and($schema['additionalProperties'] ?? null)->toBeFalse()
        ->and($propertyKeys)->toEqualCanonicalizing(['status'])
        ->and($schema['properties']['status']['enum'] ?? [])->toBe(['OK']);
});
