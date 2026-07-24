<?php

use App\Enums\PayrollCategory;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
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

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'CEXP001',
        'name' => 'Contract Export Employee',
        'status' => 'active',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'labor_contract_id' => 'LC-12345',
        'status' => 'active',
        'basic_salary' => 5000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
    ]);

    return compact('company', 'branch', 'officeDepartment', 'employee', 'contract');
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

test('contracts export can be limited to selected record ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [
        'company' => $company,
        'branch' => $branch,
        'officeDepartment' => $officeDepartment,
        'contract' => $contract,
    ] = makeContractExportFixtures();

    $excludedEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'CEXP-EXCLUDED',
        'name' => 'Excluded Contract Employee',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $excludedEmployee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => now()->subMonths(6)->toDateString(),
        'end_date' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
        'basic_salary' => 4500,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $content = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'ids' => (string) $contract->id,
        'payroll_category' => PayrollCategory::Office->value,
    ]))->streamedContent();

    expect($content)
        ->toContain('Contract Export Employee')
        ->not->toContain('Excluded Contract Employee');
});

test('export respects status filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeContractExportFixtures();

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
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

test('export respects payroll category, department, and lifecycle filters for csv and excel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'branch' => $branch] = makeContractExportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF-2',
        'status' => 'active',
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
    ]);

    $officeEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'CEXP-OFF',
        'name' => 'Office Export Employee',
        'status' => 'active',
    ]);

    $crewEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $marineDepartment->id,
        'employee_no' => 'CEXP-CREW',
        'name' => 'Crew Export Employee',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 6000,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
        'basic_salary' => 3000,
    ]);

    $officeCsv = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'payroll_category' => 'office',
        'lifecycle' => 'active',
        'department_id' => $officeDepartment->id,
    ]));
    $officeCsv->assertOk();
    $officeContent = $officeCsv->streamedContent();
    expect($officeContent)->toContain('CEXP-OFF')
        ->and($officeContent)->not->toContain('CEXP-CREW');

    $this->get(route('organization.contracts.export', [
        'format' => 'xlsx',
        'payroll_category' => 'office',
        'lifecycle' => 'active',
        'department_id' => $officeDepartment->id,
    ]))->assertOk();

    $crewCsv = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'payroll_category' => 'crew',
        'salary_structure' => 'daily',
        'lifecycle' => 'all',
    ]));
    $crewCsv->assertOk();
    $crewContent = $crewCsv->streamedContent();
    expect($crewContent)->toContain('CEXP-CREW')
        ->and($crewContent)->not->toContain('CEXP-OFF');

    $this->get(route('organization.contracts.export', [
        'format' => 'xlsx',
        'payroll_category' => 'crew',
        'salary_structure' => 'daily',
        'lifecycle' => 'all',
    ]))->assertOk();
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
        ->and($contentOffice)->not->toContain('Supplementary Allowance')
        ->and($contentOffice)->not->toContain('Branch')
        ->and($contentOffice)->not->toContain('Profile Template')
        ->and($contentOffice)->not->toContain('Payroll Category')
        ->and($contentOffice)->not->toContain('Salary Structure')
        ->and($contentOffice)->not->toContain('Created At');

    $responseCrew = $this->get(route('organization.contracts.export', ['format' => 'csv', 'payroll_category' => 'crew']));
    $responseCrew->assertOk();
    $contentCrew = $responseCrew->streamedContent();
    expect($contentCrew)->toContain('Supplementary Allowance')
        ->and($contentCrew)->not->toContain('Housing Allowance')
        ->and($contentCrew)->not->toContain('Branch')
        ->and($contentCrew)->not->toContain('Profile Template')
        ->and($contentCrew)->not->toContain('Payroll Category')
        ->and($contentCrew)->not->toContain('Salary Structure')
        ->and($contentCrew)->not->toContain('Created At');
});

test('export includes total salary usd column calculated from aed total', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeContractExportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $response = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'payroll_category' => 'office',
    ]));

    $response->assertOk();

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $headers = str_getcsv($lines[0]);
    $data = str_getcsv($lines[1]);

    expect($headers)->toContain('Total Salary')
        ->and($headers)->toContain('Total Salary (USD)')
        ->and((float) $data[array_search('Total Salary', $headers, true)])->toBe(8000.0)
        ->and((float) $data[array_search('Total Salary (USD)', $headers, true)])->toBe(2178.35);
});

test('crew export calculates usd from basic supplementary and site allowances only', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'branch' => $branch] = makeContractExportFixtures();

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR-USD',
        'status' => 'active',
    ]);

    $crewEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $marineDepartment->id,
        'employee_no' => 'CEXP-CREW-USD',
        'name' => 'Crew USD Export',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 50,
        'supplementary_allowance' => 50,
        'site_allowance' => 167,
        'housing_allowance' => 999,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $response = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'payroll_category' => 'crew',
    ]));

    $response->assertOk();

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $headers = str_getcsv($lines[0]);
    $crewRow = collect($lines)
        ->map(fn (string $line) => str_getcsv($line))
        ->first(fn (array $row) => ($row[0] ?? null) === 'CEXP-CREW-USD');

    expect($crewRow)->not->toBeNull()
        ->and((float) $crewRow[array_search('Total Salary', $headers, true)])->toBe(267.0)
        ->and((float) $crewRow[array_search('Total Salary (USD)', $headers, true)])->toBe(72.7);
});

test('export shows zero totals when contract has no salary components', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'branch' => $branch] = makeContractExportFixtures();

    $offshoreDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'code' => 'OFF-USD',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $offshoreDepartment->id,
        'employee_no' => 'CEXP-ZERO',
        'name' => 'Zero Salary Export',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $response = $this->get(route('organization.contracts.export', [
        'format' => 'csv',
        'payroll_category' => 'crew',
    ]));

    $response->assertOk();

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $headers = str_getcsv($lines[0]);
    $row = collect($lines)
        ->map(fn (string $line) => str_getcsv($line))
        ->first(fn (array $data) => ($data[0] ?? null) === 'CEXP-ZERO');

    expect($row)->not->toBeNull()
        ->and($row[array_search('Total Salary', $headers, true)])->toBe('0')
        ->and($row[array_search('Total Salary (USD)', $headers, true)])->toBe('0');
});
