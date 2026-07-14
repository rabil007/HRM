<?php

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;

function makeLeaveAttendanceActivityFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'L'.fake()->unique()->numerify('##'),
        'name' => 'Leave Activity Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'L'.fake()->unique()->numerify('##'),
        'name' => 'Leave Activity Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Leave Activity Co',
        'slug' => 'leave-activity-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee');
}

test('activity log is recorded for leave balance creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeLeaveAttendanceActivityFixtures();
    $this->actingAs($user);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    $balance = LeaveBalance::factory()
        ->forEmployee($employee)
        ->forLeaveType($leaveType)
        ->create([
            'year' => 2026,
        ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => LeaveBalance::class,
        'subject_id' => $balance->id,
    ]);
});

test('activity log is recorded for attendance record creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeLeaveAttendanceActivityFixtures();
    $this->actingAs($user);

    $record = AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-07-14',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => AttendanceRecord::class,
        'subject_id' => $record->id,
    ]);
});
