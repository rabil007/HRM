<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Inertia\Testing\AssertableInertia as Assert;

test('authorized users can create salary inputs for office payroll periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.salary_inputs.create',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-SI-01', 10000, 0, 0, 0);
    $bonusTypeId = salaryInputTypeId($company, 'bonus');

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $period), [
            'employee_id' => $employee->id,
            'salary_input_type_id' => $bonusTypeId,
            'amount' => 500,
            'notes' => 'Performance bonus',
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('salary_inputs', [
        'company_id' => $company->id,
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'salary_input_type_id' => $bonusTypeId,
        'amount' => '500.00',
        'notes' => 'Performance bonus',
    ]);
});

test('authorized users can update and delete salary inputs', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.salary_inputs.update',
        'payroll.salary_inputs.delete',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-SI-02', 10000, 0, 0, 0);

    $input = SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 200,
    ]);

    $lateTypeId = salaryInputTypeId($company, 'late');

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.salary-inputs.update', [$period, $input]), [
            'salary_input_type_id' => $lateTypeId,
            'amount' => 150,
            'notes' => 'Late arrival',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($input->refresh())
        ->salary_input_type_id->toBe($lateTypeId)
        ->amount->toBe('150.00')
        ->notes->toBe('Late arrival');

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.salary-inputs.destroy', [$period, $input]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('salary_inputs', ['id' => $input->id]);
});

test('salary input routes reject inputs from other companies', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.create']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $otherEmployee = createOfficeEmployeeWithContract($otherCompany, 'OFF-OTHER', 10000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $period), [
            'employee_id' => $otherEmployee->id,
            'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
            'amount' => 100,
        ])
        ->assertSessionHasErrors('employee_id');
});

test('salary inputs cannot be managed on approved office periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.create']);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-SI-03', 10000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $period), [
            'employee_id' => $employee->id,
            'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
            'amount' => 100,
        ])
        ->assertSessionHasErrors('period_id');
});

test('payroll show includes salary inputs grouped by employee for office periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-SI-04', 10000, 0, 0, 0);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'commission'),
        'amount' => 250,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has("salary_inputs_by_employee.{$employee->id}", 1)
            ->where("salary_inputs_by_employee.{$employee->id}.0.type", 'commission')
            ->where('payroll_records.0.salary_inputs_count', 1)
            ->has('salary_input_type_options', 6));
});
