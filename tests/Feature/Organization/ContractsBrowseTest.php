<?php

use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfileTemplate;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access employee contracts browse page', function () {
    ['employee' => $employee] = makeContractFixtures();

    $this->get(route('organization.contracts.employee', $employee))
        ->assertRedirect(route('login'));
});

test('users without contracts view cannot access employee contracts browse page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.contracts.employee', $employee))->assertForbidden();
});

test('employee contracts browse page loads contracts and template fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Contracts',
        'description' => null,
        'is_active' => true,
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee->update(['employee_profile_template_id' => $template->id]);

    grantCompanyPermissions($user, $company, [
        'contracts.view',
        'contracts.create',
        'contracts.update',
        'contracts.delete',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 7500,
    ]);

    $this->get(route('organization.contracts.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/contracts/employee')
            ->where('employee.id', $employee->id)
            ->where('employee.name', 'Contract Employee')
            ->has('contracts', 1)
            ->where('contracts.0.basic_salary', '7500.00')
            ->has('template_contract_fields.contract_type')
            ->where('can.view', true)
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true)
            ->has('back'));
});
