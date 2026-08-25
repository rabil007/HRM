<?php

use App\Support\Ai\StructuredAgentOutput;

test('decode unwraps markdown fenced json from OpenRouter', function () {
    $text = <<<'TEXT'
```json
{
  "status": "active",
  "department": null,
  "unsupported_terms": []
}
```
TEXT;

    expect(StructuredAgentOutput::decode($text))->toMatchArray([
        'status' => 'active',
        'department' => null,
        'unsupported_terms' => [],
    ]);
});

test('decode accepts raw json objects', function () {
    expect(StructuredAgentOutput::decode('{"status":"OK"}'))->toBe([
        'status' => 'OK',
    ]);
});

test('decode returns an empty array for invalid payloads', function () {
    expect(StructuredAgentOutput::decode(''))->toBe([])
        ->and(StructuredAgentOutput::decode('not json'))->toBe([]);
});
