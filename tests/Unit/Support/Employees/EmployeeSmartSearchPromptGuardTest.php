<?php

use App\Support\Employees\EmployeeSmartSearchPromptGuard;

test('it blocks specific identifier lookups', function (string $prompt) {
    expect(EmployeeSmartSearchPromptGuard::shouldBlock($prompt))->toBeTrue();
})->with([
    'emirates id' => ['employee with Emirates ID 784-2000-1234567-1'],
    'email address' => ['employee with email jane@example.test'],
    'phone lookup' => ['employee with phone +971501234567'],
    'passport number' => ['employee with passport A12345678'],
    'employee number' => ['employee number EMP-1001'],
    'named person' => ['employee named Ahmed Khan'],
]);

test('it does not block category presence language', function (string $prompt) {
    expect(EmployeeSmartSearchPromptGuard::shouldBlock($prompt))->toBeFalse();
})->with([
    'without email' => ['employees without email'],
    'with email' => ['employees with email'],
    'without phone' => ['employees without phone'],
    'missing emirates id' => ['employees missing Emirates ID'],
    'without passport' => ['employees without passport'],
    'with passport' => ['employees with passport'],
    'rank abbreviation' => ['AB crew'],
]);
