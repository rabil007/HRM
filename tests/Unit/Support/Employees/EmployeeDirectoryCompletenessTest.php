<?php

use App\Support\Employees\EmployeeDirectoryCompleteness;

test('it canonicalizes completeness keys and rejects unknown fields', function () {
    expect(EmployeeDirectoryCompleteness::parse('phone,email,date_of_birth'))->toMatchArray([
        'keys' => ['email', 'phone', 'date_of_birth'],
        'unknown' => [],
        'valid' => true,
    ])
        ->and(EmployeeDirectoryCompleteness::toCsv(['phone', 'email', 'email']))
        ->toBe('email,phone')
        ->and(EmployeeDirectoryCompleteness::parse('salary,iban'))->toMatchArray([
            'keys' => [],
            'unknown' => ['salary', 'iban'],
            'valid' => false,
        ]);
});

test('malformed completeness types fail closed', function (mixed $value) {
    expect(EmployeeDirectoryCompleteness::parse($value))->toMatchArray([
        'keys' => [],
        'unknown' => [],
        'valid' => false,
    ]);
})->with([
    'array' => [['salary']],
    'associative array' => [['email' => 'salary']],
    'null' => [null],
    'boolean' => [true],
]);
