<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCompanyForFormOptions(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => $suffix,
        'name' => "Country {$suffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $suffix,
        'name' => "Currency {$suffix}",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => "Company {$suffix}",
        'slug' => strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('employee form options scopes company bound lookups to the requested company', function () {
    $companyA = createCompanyForFormOptions('FOA');
    $companyB = createCompanyForFormOptions('FOB');

    $branchA = Branch::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Alpha Branch',
        'is_headquarters' => true,
        'status' => 'active',
    ]);

    Branch::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Beta Branch',
        'is_headquarters' => true,
        'status' => 'active',
    ]);

    $options = EmployeeFormOptions::for($companyA->id);

    expect($options['branches'])->toHaveCount(1)
        ->and($options['branches']->first()->id)->toBe($branchA->id);
});

test('employee form options exposes department managers for directory filters', function () {
    $employee = Employee::factory()->create();
    $company = $employee->company;

    $manager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DM100',
        'name' => 'Department Manager',
    ]);

    Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $options = EmployeeFormOptions::departmentManagersForFilter($company->id);

    expect($options)->toHaveCount(1)
        ->and($options->first()->id)->toBe($manager->id);
});

test('employee form options for create returns nested onboarding option keys', function () {
    $employee = Employee::factory()->create();

    $options = EmployeeFormOptions::forCreate($employee->company_id);

    expect($options)->toHaveKeys([
        'branches',
        'departments',
        'positions',
        'countries',
        'religions',
        'genders',
        'banks',
        'ranks',
        'projects',
        'document_types',
    ]);
});
