<?php

use App\Support\Payroll\CrewOvertimePay;

test('crew overtime pay uses monthly salary divided by 365 with 1.25 multiplier', function () {
    $result = (new CrewOvertimePay)->calculate(76, 8040);

    expect($result['hours'])->toBe(76.0)
        ->and($result['monthly_salary'])->toBe(8040.0)
        ->and($result['overtime_pay'])->toBe(2092.60);
});

test('crew overtime pay returns zero breakdown when hours are zero', function () {
    $result = (new CrewOvertimePay)->calculate(0, 8040);

    expect($result)->toMatchArray([
        'hours' => 0.0,
        'monthly_salary' => 0.0,
        'hour_rate' => 0.0,
        'overtime_hourly_rate' => 0.0,
        'overtime_pay' => 0.0,
    ]);
});

test('crew overtime pay matches rajib mondal sample', function () {
    $result = (new CrewOvertimePay)->calculate(98, 5040);

    expect($result['overtime_pay'])->toBe(1691.51);
});

test('crew overtime pay matches hamza sample', function () {
    $result = (new CrewOvertimePay)->calculate(56, 5040);

    expect($result['overtime_pay'])->toBe(966.58);
});
