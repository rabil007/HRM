<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;

test('activity log is recorded for payroll period creation and update', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $period = PayrollPeriod::factory()->for($company)->create([
        'name' => 'June 2026',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => PayrollPeriod::class,
        'subject_id' => $period->id,
    ]);

    $period->update(['status' => PayrollPeriodStatus::Processing]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'updated',
        'subject_type' => PayrollPeriod::class,
        'subject_id' => $period->id,
    ]);
});

test('activity log is recorded for payroll record creation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'net_salary' => 5000,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => PayrollRecord::class,
        'subject_id' => $record->id,
    ]);
});

test('activity log is recorded for crew timesheet creation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => CrewTimesheet::class,
        'subject_id' => $timesheet->id,
    ]);
});

test('activity log is recorded for salary input type creation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $type = SalaryInputType::query()->create([
        'company_id' => $company->id,
        'name' => 'Special Bonus',
        'code' => 'special_bonus_'.uniqid(),
        'is_addition' => true,
        'status' => 'active',
        'sort_order' => 99,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => SalaryInputType::class,
        'subject_id' => $type->id,
    ]);
});

test('activity log is recorded for salary input creation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $period = PayrollPeriod::factory()->for($company)->office()->create();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $input = SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 250,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => SalaryInput::class,
        'subject_id' => $input->id,
    ]);
});
