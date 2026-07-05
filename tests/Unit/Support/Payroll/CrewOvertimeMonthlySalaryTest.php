<?php

use App\Support\Payroll\CrewOvertimeMonthlySalary;

test('crew overtime monthly salary equals period days times combined daily onsite rates', function () {
    expect(CrewOvertimeMonthlySalary::fromDailyRates(30, 33.5, 250, 66.5))->toBe(10500.0)
        ->and(CrewOvertimeMonthlySalary::fromDailyRates(30, 150, 50, 75))->toBe(8250.0);
});

test('crew overtime monthly salary returns zero when period has no days', function () {
    expect(CrewOvertimeMonthlySalary::fromDailyRates(0, 150, 50, 75))->toBe(0.0);
});
