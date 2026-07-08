<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeActiveOnlyScopeFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'AO1'],
        ['name' => 'Active Only Land', 'dial_code' => '+903', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AO1'],
        ['name' => 'Active Only Currency', 'symbol' => 'A$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'ActiveOnlyCo',
        'slug' => 'activeonlyco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $activeEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'ACT001',
        'name' => 'Active Employee',
        'status' => 'active',
    ]);

    $terminatedEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'TRM001',
        'name' => 'Terminated Employee',
        'status' => 'terminated',
    ]);

    return compact('company', 'branch', 'officeDepartment', 'activeEmployee', 'terminatedEmployee');
}

test('employee directory defaults to active employees only', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 1)
            ->where('employees.0.id', $activeEmployee->id)
            ->where('employees.0.name', 'Active Employee'));
});

test('employee directory can still filter by non-active status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'terminatedEmployee' => $terminatedEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees', ['status' => 'terminated']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 1)
            ->where('employees.0.id', $terminatedEmployee->id));
});

test('contracts no contract list excludes terminated employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $this->get(route('organization.contracts.no-contract'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/contracts/no-contract')
            ->has('employees', 1)
            ->where('employees.0.id', $activeEmployee->id));
});

test('bank accounts summary excludes terminated employees from no account count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->get(route('organization.bank-accounts'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('summary.no_account_employees', 1));
});
