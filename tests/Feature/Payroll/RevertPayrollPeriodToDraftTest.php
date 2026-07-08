<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;

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
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Draft);
    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0);

    $showUrl = route('payroll.show', ['payrollPeriod' => $period]);

    $this->withSession(['current_company_id' => $company->id])
        ->from($showUrl)
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'standby_days' => 4,
        ])
        ->assertRedirect($showUrl);

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

test('revert to draft clears excluded employee ids', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
        'excluded_employee_ids' => [$employee->id],
    ]);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and($period->excluded_employee_ids)->toBeNull();
});

test('removed office employee can be regenerated after revert to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.revert_to_draft',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $removedEmployee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);
    $remainingEmployee = createOfficeEmployeeWithContract($company, 'OFF-200', 8000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($removedEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($remainingEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [
            'payrollPeriod' => $period,
            'payrollRecord' => PayrollRecord::query()
                ->where('period_id', $period->id)
                ->where('employee_id', $removedEmployee->id)
                ->firstOrFail(),
        ]))
        ->assertRedirect();

    $period->refresh();
    expect($period->excluded_employee_ids)->toBe([$removedEmployee->id]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect();

    $period->refresh();
    expect($period->excluded_employee_ids)->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $removedEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $remainingEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(2);
});

test('revert to draft removes salary inputs for the pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 500,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SalaryInput::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('reverting approved crew pay period to draft removes all payroll records', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Approved,
        'approved_by' => $user->id,
        'approved_at' => now(),
    ]);

    $firstEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $secondEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    foreach ([$firstEmployee, $secondEmployee] as $employee) {
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'payroll_category' => PayrollCategory::Crew,
            'status' => 'active',
        ]);
    }

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $firstEmployee->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
    ]);
    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $secondEmployee->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and($period->approved_by)->toBeNull()
        ->and($period->approved_at)->toBeNull()
        ->and($period->excluded_employee_ids)->toBeNull()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(SalaryInput::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(0);
});
