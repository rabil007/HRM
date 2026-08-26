<?php

use App\Exceptions\EmployeeSmartSearchUnavailableException;
use App\Support\Employees\EmployeeSmartSearchIntent;

test('it accepts a fully nullable schema including empty unsupported terms', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'status' => null,
        'department' => null,
        'position' => null,
        'nationality' => null,
        'rank' => null,
        'crew_status' => null,
        'unsupported_terms' => [],
    ]))->toBe([
        'status' => null,
        'department' => null,
        'position' => null,
        'nationality' => null,
        'rank' => null,
        'crew_status' => null,
        'emirates_id_presence' => null,
        'unsupported_terms' => [],
    ]);
});

test('it accepts raw objects that omit nullable fields', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'status' => 'active',
        'unsupported_terms' => [],
    ]))->toMatchArray([
        'status' => 'active',
        'department' => null,
        'unsupported_terms' => [],
    ]);
});

test('it discards unexpected extra fields', function () {
    $intent = EmployeeSmartSearchIntent::fromDecoded([
        'status' => 'active',
        'company_id' => 99,
        'department_id' => 1,
        'filters' => ['status' => 'terminated'],
        'sql' => 'select * from employees',
        'unsupported_terms' => ['salary'],
    ]);

    expect($intent)->toMatchArray([
        'status' => 'active',
        'unsupported_terms' => ['salary'],
    ])
        ->and($intent)->not->toHaveKey('company_id')
        ->and($intent)->not->toHaveKey('department_id')
        ->and($intent)->not->toHaveKey('filters')
        ->and($intent)->not->toHaveKey('sql');
});

test('it rejects empty, list, and unstructured payloads', function (array $payload) {
    expect(fn () => EmployeeSmartSearchIntent::fromDecoded($payload))
        ->toThrow(EmployeeSmartSearchUnavailableException::class);
})->with([
    'empty array' => [[]],
    'list array' => [['active', 'crew']],
    'missing unsupported terms' => [['status' => 'active']],
    'only extras' => [['company_id' => 2, 'department_id' => 1]],
    'extras with empty unsupported terms' => [['company_id' => 2, 'unsupported_terms' => []]],
    'wrong status type' => [['status' => 1, 'unsupported_terms' => []]],
    'wrong unsupported type' => [['status' => 'active', 'unsupported_terms' => 'salary']],
    'non-string unsupported items' => [['status' => 'active', 'unsupported_terms' => [1]]],
    'invalid emirates id presence' => [['emirates_id_presence' => '784-1234-1234567-1', 'unsupported_terms' => []]],
    'non-enum emirates id presence' => [['emirates_id_presence' => 'blank', 'unsupported_terms' => []]],
]);

test('it accepts missing and present emirates id presence values', function (string $value) {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'emirates_id_presence' => $value,
        'unsupported_terms' => [],
    ]))->toMatchArray([
        'emirates_id_presence' => $value,
        'unsupported_terms' => [],
    ]);
})->with(['missing', 'present']);

test('it treats null emirates id presence as unset', function () {
    expect(EmployeeSmartSearchIntent::fromDecoded([
        'status' => 'active',
        'emirates_id_presence' => null,
        'unsupported_terms' => [],
    ]))->toMatchArray([
        'status' => 'active',
        'emirates_id_presence' => null,
    ]);
});
