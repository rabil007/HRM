<?php

use App\Enums\LeaveTypeCategory;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Attendance\AnnualLeaveEntitlementCalculator;
use Carbon\Carbon;

test('annual leave pro rata examples use ceil and calendar days', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;
    $hire = Carbon::parse('2026-02-02');

    expect($calculator->proRataForJoiningYear(Carbon::parse('2026-01-01'), 2026, 30.0))->toBe(30.0)
        ->and($calculator->proRataForJoiningYear($hire, 2026, 30.0))->toBe(28.0)
        ->and($calculator->proRataForJoiningYear(Carbon::parse('2026-10-01'), 2026, 30.0))->toBe(8.0)
        ->and($calculator->proRataForJoiningYear(Carbon::parse('2026-12-31'), 2026, 30.0))->toBe(1.0);
});

test('continuing employees receive full configured entitlement in later years', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
    ]);

    expect($calculator->entitledDaysForNewBalance($annual, $employee, 2027))->toBe(30.0);
});

test('leap year uses 366 calendar days', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;

    expect($calculator->calendarDaysInYear(2024))->toBe(366)
        ->and($calculator->proRataForJoiningYear(Carbon::parse('2024-12-31'), 2024, 30.0))->toBe(1.0)
        ->and($calculator->proRataForJoiningYear(Carbon::parse('2024-07-01'), 2024, 30.0))->toBe(16.0);
});

test('non annual leave types keep configured days per year', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'hire_date' => '2026-10-01',
    ]);
    $sick = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Sick,
        'days_per_year' => 15,
    ]);

    expect($calculator->entitledDaysForNewBalance($sick, $employee, 2026))->toBe(15.0);
});

test('missing hire date yields zero annual entitlement not full year', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'hire_date' => null,
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
    ]);

    expect($calculator->entitledDaysForNewBalance($annual, $employee, 2026))->toBe(0.0);
});

test('years before hire date are not provisioned', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'hire_date' => '2026-06-01',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
    ]);

    expect($calculator->entitledDaysForNewBalance($annual, $employee, 2025))->toBeNull();
});

test('non default configured annual entitlement is respected', function () {
    $calculator = new AnnualLeaveEntitlementCalculator;

    expect($calculator->proRataForJoiningYear(Carbon::parse('2026-10-01'), 2026, 22.0))->toBe(6.0);
});
