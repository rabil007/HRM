<?php

use App\Exceptions\EmployeeSmartSearchUnavailableException;
use App\Support\Employees\EmployeeSmartSearchIntent;

test('it accepts an empty but structurally complete criteria payload', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'criteria' => [],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]))->toBe([
        'criteria' => [],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);
});

test('it accepts required nested criterion properties including a null value', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'criteria' => [[
            'concept' => 'email',
            'operator' => 'missing',
            'value' => null,
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]))->toBe([
        'criteria' => [[
            'concept' => 'email',
            'operator' => 'missing',
            'value' => null,
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);
});

test('it discards unexpected extra fields', function () {
    $intent = EmployeeSmartSearchIntent::fromDecoded([
        'criteria' => [[
            'concept' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => ['salary'],
        'company_id' => 99,
        'department_id' => 1,
        'filters' => ['status' => 'terminated'],
        'sql' => 'select * from employees',
    ]);

    expect($intent)->toMatchArray([
        'criteria' => [[
            'concept' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]],
        'unsupported_terms' => ['salary'],
    ])
        ->and($intent)->not->toHaveKey('company_id')
        ->and($intent)->not->toHaveKey('department_id')
        ->and($intent)->not->toHaveKey('filters')
        ->and($intent)->not->toHaveKey('sql');
});

test('it rejects empty, list, and structurally incomplete payloads', function (array $payload) {
    expect(fn () => EmployeeSmartSearchIntent::fromDecoded($payload))
        ->toThrow(EmployeeSmartSearchUnavailableException::class);
})->with([
    'empty array' => [[]],
    'list array' => [['active', 'crew']],
    'missing criteria' => [['ambiguous_terms' => [], 'unsupported_terms' => []]],
    'missing ambiguous terms' => [['criteria' => [], 'unsupported_terms' => []]],
    'missing unsupported terms' => [['criteria' => [], 'ambiguous_terms' => []]],
    'only extras' => [['company_id' => 2, 'department_id' => 1]],
    'legacy scalar fields' => [['status' => 'active', 'unsupported_terms' => []]],
    'missing nested value' => [[
        'criteria' => [['concept' => 'status', 'operator' => 'equals']],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]],
    'missing nested operator' => [[
        'criteria' => [['concept' => 'status', 'value' => 'active']],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]],
    'unknown concept' => [[
        'criteria' => [['concept' => 'salary', 'operator' => 'equals', 'value' => '9000']],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]],
    'disallowed operator' => [[
        'criteria' => [['concept' => 'status', 'operator' => 'missing', 'value' => null]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]],
    'wrong unsupported type' => [['criteria' => [], 'ambiguous_terms' => [], 'unsupported_terms' => 'salary']],
    'non-string unsupported items' => [['criteria' => [], 'ambiguous_terms' => [], 'unsupported_terms' => [1]]],
]);

test('it deduplicates identical criteria', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'criteria' => [
            ['concept' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ['concept' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ])['criteria'])->toHaveCount(1);
});
