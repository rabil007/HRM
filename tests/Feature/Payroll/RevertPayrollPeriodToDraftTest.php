<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;

test('users without permission cannot revert pay period to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertForbidden();
});

test('authorized users can revert processing pay period to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.revert_to_draft',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'standby_days' => 2,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'timesheets']))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Draft);
    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'standby_days' => 4,
        ])
        ->assertRedirect(route('payroll.show', $period));

    $this->assertDatabaseHas('crew_timesheets', [
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'standby_days' => 4,
    ]);
});

test('draft pay periods cannot be reverted to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertSessionHasErrors('period_id');
});

test('paid pay periods cannot be reverted to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertSessionHasErrors('period_id');
});
