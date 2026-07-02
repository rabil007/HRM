<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\RecalculateOfficePayroll;
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
        'gross_salary' => 10000,
        'net_salary' => 10000,
        'calculation_breakdown' => [
            'base' => [
                'basic' => 10000,
                'housing' => 0,
                'transport' => 0,
                'other' => 0,
                'gross' => 10000,
                'net' => 10000,
                'bonus' => 0,
                'unpaid_leave_deduction' => 0,
            ],
        ],
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

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->gross_salary)->toBe('10500.00')
        ->and($record->net_salary)->toBe('10500.00');
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

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000,
        'net_salary' => 9800,
        'late_deduction' => 200,
        'total_deductions' => 200,
        'calculation_breakdown' => [
            'base' => [
                'basic' => 10000,
                'housing' => 0,
                'transport' => 0,
                'other' => 0,
                'gross' => 10000,
                'net' => 10000,
                'bonus' => 0,
                'unpaid_leave_deduction' => 0,
            ],
        ],
    ]);

    $input = SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 200,
    ]);

    app(RecalculateOfficePayroll::class)->handle($period, $employee->id);

    $record->refresh();

    expect($record->gross_salary)->toBe('10000.00')
        ->and($record->net_salary)->toBe('9800.00');

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

    $record->refresh();

    expect($record->late_deduction)->toBe('150.00')
        ->and($record->net_salary)->toBe('9850.00');

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.salary-inputs.destroy', [$period, $input]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('salary_inputs', ['id' => $input->id]);

    $record->refresh();

    expect($record->late_deduction)->toBe('0.00')
        ->and($record->net_salary)->toBe('10000.00');
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

test('payroll show includes salary input type options for crew periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('salary_input_type_options', 6)
            ->where('salary_input_type_options.0.label', 'Bonus'));
});

test('creating salary inputs immediately updates payroll record amounts', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.create']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-SI-05', 10000, 0, 0, 0);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000,
        'net_salary' => 10000,
        'calculation_breakdown' => [
            'base' => [
                'basic' => 10000,
                'housing' => 0,
                'transport' => 0,
                'other' => 0,
                'gross' => 10000,
                'net' => 10000,
                'bonus' => 0,
                'unpaid_leave_deduction' => 0,
            ],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $period), [
            'employee_id' => $employee->id,
            'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
            'amount' => 500,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $record->refresh();

    expect($record->gross_salary)->toBe('10500.00')
        ->and($record->net_salary)->toBe('10500.00');
});

test('authorized users can create salary inputs for crew payroll periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.salary_inputs.create',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-SI-01',
        'status' => 'active',
    ]);
    $bonusTypeId = salaryInputTypeId($company, 'bonus');

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 0,
        'total_deductions' => 0,
        'gross_salary' => 1000,
        'net_salary' => 1000,
        'calculation_breakdown' => [
            'base' => [
                'gross' => 1000,
                'net' => 1000,
                'bonus' => 0,
                'other_deductions' => 0,
            ],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $period), [
            'employee_id' => $employee->id,
            'salary_input_type_id' => $bonusTypeId,
            'amount' => 250,
            'notes' => 'Crew bonus',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('salary_inputs', [
        'company_id' => $company->id,
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'salary_input_type_id' => $bonusTypeId,
        'amount' => '250.00',
        'notes' => 'Crew bonus',
    ]);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->bonus)->toBe('250.00')
        ->and($record->gross_salary)->toBe('1250.00')
        ->and($record->net_salary)->toBe('1250.00');
});
