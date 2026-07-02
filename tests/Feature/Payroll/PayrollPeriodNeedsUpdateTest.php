<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;
use App\Support\Payroll\Actions\RecalculateOfficePayroll;
use App\Support\Payroll\PayrollPeriodNeedsUpdate;
use Inertia\Testing\AssertableInertia as Assert;

test('payroll period needs update when salary inputs change after generation', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $period->loadCount('payrollRecords');

    $employee = createOfficeEmployeeWithContract($company, 'OFF-NU-01', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'calculation_breakdown' => [
            'salary_inputs' => [],
        ],
    ]);

    $detector = app(PayrollPeriodNeedsUpdate::class);

    expect($detector->forPeriod($period))->toMatchArray([
        'needs_update' => false,
        'reasons' => [],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 500,
    ]);

    expect($detector->forPeriod($period->fresh()))->toMatchArray([
        'needs_update' => true,
        'reasons' => ['salary_inputs'],
    ]);
});

test('payroll period does not need update after salary inputs are recalculated', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $period->loadCount('payrollRecords');

    $employee = createOfficeEmployeeWithContract($company, 'OFF-NU-02', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000,
        'net_salary' => 10000,
        'calculation_breakdown' => [
            'base' => [
                'gross' => 10000,
                'net' => 10000,
                'bonus' => 0,
                'unpaid_leave_deduction' => 0,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 500,
    ]);

    app(RecalculateOfficePayroll::class)->handle($period);

    $detector = app(PayrollPeriodNeedsUpdate::class);

    expect($detector->forPeriod($period->fresh()))->toMatchArray([
        'needs_update' => false,
        'reasons' => [],
    ]);
});

test('payroll period needs update when crew timesheets change after generation', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $period->loadCount('payrollRecords');

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-NU-01',
        'status' => 'active',
    ]);

    $timesheet = CrewTimesheet::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'standby_days' => 2,
        'onsite_days' => 20,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'calculation_breakdown' => [
            'standby_days' => 2,
            'onsite_days' => 20,
        ],
    ]);

    $detector = app(PayrollPeriodNeedsUpdate::class);

    expect($detector->forPeriod($period))->toMatchArray([
        'needs_update' => false,
        'reasons' => [],
    ]);

    $timesheet->update(['onsite_days' => 22]);

    expect($detector->forPeriod($period->fresh()))->toMatchArray([
        'needs_update' => true,
        'reasons' => ['timesheets'],
    ]);
});

test('payroll period needs update when new crew timesheets exist without payroll records', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $period->loadCount('payrollRecords');

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-NU-02',
        'status' => 'active',
    ]);

    CrewTimesheet::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'standby_days' => 1,
        'onsite_days' => 10,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => Employee::factory()->forCompany($company)->create()->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $period->loadCount('payrollRecords');

    $detector = app(PayrollPeriodNeedsUpdate::class);

    expect($detector->forPeriod($period))->toMatchArray([
        'needs_update' => true,
        'reasons' => ['new_timesheets'],
    ]);
});

test('payroll show exposes needs payroll update when salary inputs are stale', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-NU-03', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'calculation_breakdown' => [
            'salary_inputs' => [],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.needs_payroll_update', true)
            ->where('period.needs_payroll_update_reasons', ['salary_inputs']));
});

test('crew payroll recalculation clears needs payroll update flag', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $period->loadCount('payrollRecords');

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-NU-03',
        'status' => 'active',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 1000,
        'net_salary' => 1000,
        'calculation_breakdown' => [
            'base' => [
                'gross' => 1000,
                'net' => 1000,
                'bonus' => 0,
                'other_deductions' => 0,
            ],
            'salary_inputs' => [],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 100,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $detector = app(PayrollPeriodNeedsUpdate::class);

    expect($detector->forPeriod($period->fresh()))->toMatchArray([
        'needs_update' => false,
        'reasons' => [],
    ]);
});
