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
    'named person lowercase' => ['employee named ahmed'],
    'called person lowercase' => ['employee called john smith'],
    'name is lowercase' => ['name is mohammed'],
    'under person' => ['employees under Ahmed'],
    'managed by person' => ['employees managed by Ahmed Khan'],
    'reporting to lowercase' => ['employees reporting to ahmed'],
    'who report to' => ['employees who report to mohammed'],
    'with manager' => ['employees with manager John'],
    'manager is' => ['manager is Ahmed'],
    'reports to' => ['employees reports to Ahmed'],
    'report to' => ['employees report to Ahmed'],
    'supervised by' => ['employees supervised by Ahmed'],
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
    'under age' => ['employees under 30'],
    'without manager' => ['employees without manager'],
    'manager missing' => ['manager missing'],
    'department manager missing' => ['department manager missing'],
    'under department' => ['employees under Crewing department'],
]);
