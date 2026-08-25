<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;

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

/**
 * @return array{user: User, company: Company}
 */
function makeVisaTypeContractFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CVT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Type Contract Land',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Type Contract Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Type Contract Co',
        'slug' => 'visa-type-contract-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['user' => $user, 'company' => $company];
}
