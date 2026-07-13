<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Inertia\Testing\AssertableInertia as Assert;

test('users without permission cannot view salary inputs index', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.salary-inputs.index'))
        ->assertForbidden();
});

test('salary inputs index lists company types with usage counts', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'name' => 'Office June',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Office Worker',
        'employee_no' => 'OFF-700',
    ]);
    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => 5000,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($employee->currentContract);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 250,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 100,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.salary-inputs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/salary-inputs')
            ->has('salary_input_types', 6)
            ->where('salary_input_types.0.salary_inputs_count', fn ($count) => $count >= 0));
});

test('users with payroll update permission can view salary inputs index', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.salary-inputs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('payroll/salary-inputs'));
});

test('authorized users can create update and delete salary input types', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.salary_inputs.create',
        'payroll.salary_inputs.update',
        'payroll.salary_inputs.delete',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-input-types.store'), [
            'name' => 'Shift allowance',
            'code' => 'shift_allowance',
            'is_addition' => true,
            'status' => 'active',
        ])
        ->assertRedirect(route('payroll.salary-inputs.index'))
        ->assertSessionHas('success');

    $type = SalaryInputType::query()
        ->where('company_id', $company->id)
        ->where('code', 'shift_allowance')
        ->firstOrFail();

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.salary-input-types.update', $type), [
            'name' => 'Shift allowance updated',
            'code' => 'shift_allowance',
            'is_addition' => true,
            'status' => 'active',
        ])
        ->assertRedirect(route('payroll.salary-inputs.index'))
        ->assertSessionHas('success');

    expect($type->refresh()->name)->toBe('Shift allowance updated');

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.salary-input-types.destroy', $type))
        ->assertRedirect(route('payroll.salary-inputs.index'))
        ->assertSessionHas('success');

    $this->assertSoftDeleted('salary_input_types', ['id' => $type->id]);
});

test('deleting a default salary input type does not recreate it on index reload', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.salary_inputs.delete',
        'payroll.salary_inputs.view',
    ]);

    $bonusType = SalaryInputType::query()
        ->where('company_id', $company->id)
        ->where('code', 'bonus')
        ->firstOrFail();

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.salary-input-types.destroy', $bonusType))
        ->assertRedirect(route('payroll.salary-inputs.index'))
        ->assertSessionHas('success');

    $this->assertSoftDeleted('salary_input_types', [
        'company_id' => $company->id,
        'code' => 'bonus',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.salary-inputs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/salary-inputs')
            ->has('salary_input_types', 5)
            ->where('salary_input_types', fn ($types) => collect($types)->pluck('code')->doesntContain('bonus')));
});

test('salary input types cannot be deleted when used in pay runs', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.delete']);

    $period = PayrollPeriod::factory()->for($company)->office()->create();
    $employee = Employee::factory()->forCompany($company)->create();
    $typeId = salaryInputTypeId($company, 'bonus');

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => $typeId,
    ]);

    $type = SalaryInputType::query()->findOrFail($typeId);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.salary-input-types.destroy', $type))
        ->assertRedirect(route('payroll.salary-inputs.index'))
        ->assertSessionHasErrors('salary_input_type');

    $this->assertDatabaseHas('salary_input_types', ['id' => $type->id]);
});
