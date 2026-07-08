<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;

function makeContractExportFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'CEX'],
        ['name' => 'Contract Export Land', 'dial_code' => '+905', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'CEX'],
        ['name' => 'Contract Export Currency', 'symbol' => 'C$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'ContractExportCo',
        'slug' => 'contract-exportco-'.uniqid(),
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
        'employee_no' => 'CEXP001',
        'name' => 'Contract Export Employee',
        'status' => 'active',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'labor_contract_id' => 'LC-12345',
        'status' => 'active',
        'basic_salary' => 5000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
    ]);

    return compact('company', 'branch', 'employee', 'contract');
}

test('guests cannot access contracts export', function () {
    $this->get(route('organization.contracts.export'))->assertRedirect(route('login'));
});

test('users without contracts view permission cannot export contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeContractExportFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.contracts.export'))->assertForbidden();
});

test('authenticated users with permission can export contracts as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeContractExportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $this->get(route('organization.contracts.export', ['format' => 'csv']))->assertOk();
    $this->get(route('organization.contracts.export', ['format' => 'xlsx']))->assertOk();
    $this->get(route('organization.contracts.export', ['format' => 'pdf']))->assertOk();
});

test('export respects status filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractExportFixtures();

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => now()->subYears(2)->toDateString(),
        'end_date' => now()->subYear()->toDateString(),
        'labor_contract_id' => 'LC-00000',
        'status' => 'terminated',
        'basic_salary' => 4000,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $this->get(route('organization.contracts.export', ['format' => 'csv', 'status' => 'active']))
        ->assertOk();

    $this->get(route('organization.contracts.export', ['format' => 'csv', 'status' => 'terminated']))
        ->assertOk();
});

test('export adjusts structure based on payroll category filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeContractExportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $responseOffice = $this->get(route('organization.contracts.export', ['format' => 'csv', 'payroll_category' => 'office']));
    $responseOffice->assertOk();
    $contentOffice = $responseOffice->streamedContent();
    expect($contentOffice)->toContain('Housing Allowance')
        ->and($contentOffice)->not->toContain('Supplementary Allowance');

    $responseCrew = $this->get(route('organization.contracts.export', ['format' => 'csv', 'payroll_category' => 'crew']));
    $responseCrew->assertOk();
    $contentCrew = $responseCrew->streamedContent();
    expect($contentCrew)->toContain('Supplementary Allowance')
        ->and($contentCrew)->not->toContain('Housing Allowance');
});
