<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access contract show page', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create();
    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 5000,
        'status' => 'active',
    ]);

    $this->get(route('organization.contracts.show', $contract))
        ->assertRedirect(route('login'));
});

test('users without contracts view cannot access contract show page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $employee = Employee::factory()->forCompany($company)->create();
    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 5000,
        'status' => 'active',
    ]);

    $this->get(route('organization.contracts.show', $contract))->assertForbidden();
});

test('authorized user can view contract detail with salary revisions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'contracts.view',
        'contracts.salary_revisions.view',
        'contracts.salary_revisions.create',
        'contracts.salary_revisions.update',
        'contracts.salary_revisions.delete',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Show Contract Employee',
        'employee_no' => 'SHOW-CTR-1',
    ]);

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'daily',
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
        'status' => 'active',
    ]);

    app(ApplyContractSalaryRevision::class)->handle(
        $contract,
        [
            'basic_salary' => 100,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        '2026-01-01',
        'Initial',
        $user->id,
    );

    $this->get(route('organization.contracts.show', $contract))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/contracts/show')
            ->where('contract.id', $contract->id)
            ->where('contract.employee_id', $employee->id)
            ->where('contract.basic_salary', '100.00')
            ->has('contract.salary_revisions', 1)
            ->where('can.view', true)
            ->where('can.salary_revisions_view', true)
            ->where('can.salary_revisions_create', true)
            ->where('can.salary_revisions_update', true)
            ->where('can.salary_revisions_delete', true)
            ->has('back')
            ->has('recent_activity')
            ->where('can_view_audit', false));
});
