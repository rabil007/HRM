<?php

use App\Enums\PayrollCategory;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeContractFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'CT1'],
        ['name' => 'Contract Test Land', 'dial_code' => '+901', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'CT1'],
        ['name' => 'Contract Test Currency', 'symbol' => 'C$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'ContractCo',
        'slug' => 'contractco-'.uniqid(),
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

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'CTR001',
        'name' => 'Contract Employee',
        'status' => 'active',
    ]);

    return compact('company', 'branch', 'employee');
}

test('guests cannot access contracts index', function () {
    $this->get(route('organization.contracts'))->assertRedirect(route('login'));
});

test('users with employees view but without contracts view cannot access contracts module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeContractFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.contracts'))->assertForbidden();
});

test('contracts index returns paginated contracts with summary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'limited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
    ]);

    $this->get(route('organization.contracts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/contracts/index')
            ->where('lifecycle', 'all')
            ->where('summary.total_contracts', 2)
            ->where('summary.active', 1)
            ->where('summary.ended', 1)
            ->has('contracts', 2)
            ->where('contracts.0.employee_name', 'Contract Employee')
            ->where('can.view', true)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false));
});

test('contracts index returns employee image with each contract row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    $employee->update(['image' => 'employees/photos/contract-employee.jpg']);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->get(route('organization.contracts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('contracts', 1)
            ->where('contracts.0.employee_image', 'employees/photos/contract-employee.jpg'));
});

test('contracts index filters by lifecycle and payroll category', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'limited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
    ]);

    $this->get(route('organization.contracts', ['lifecycle' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lifecycle', 'active')
            ->has('contracts', 1)
            ->where('contracts.0.payroll_category', 'office'));

    $this->get(route('organization.contracts', ['payroll_category' => 'crew']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payroll_category', 'crew')
            ->has('contracts', 1)
            ->where('contracts.0.payroll_category', 'crew'));
});

test('contracts index supports search by employee name and labor contract id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'labor_contract_id' => 'LAB-9001',
    ]);

    $this->get(route('organization.contracts', ['search' => 'Contract Employee']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('contracts', 1));

    $this->get(route('organization.contracts', ['search' => 'LAB-9001']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('contracts', 1)
            ->where('contracts.0.labor_contract_id', 'LAB-9001'));
});

test('contracts index scopes data to current company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractFixtures();

    $otherCountry = Country::query()->firstOrCreate(
        ['code' => 'CT2'],
        ['name' => 'Other Land', 'dial_code' => '+902', 'is_active' => true],
    );

    $otherCurrency = Currency::query()->firstOrCreate(
        ['code' => 'CT2'],
        ['name' => 'Other Currency', 'symbol' => 'O$', 'is_active' => true],
    );

    $otherCompany = Company::query()->create([
        'name' => 'OtherCo',
        'slug' => 'otherco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'OTH001',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->get(route('organization.contracts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('contracts', 1));
});
