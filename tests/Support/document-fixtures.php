<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Employee;

function makeDocumentFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Land', 'dial_code' => '+900', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Currency', 'symbol' => 'D$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'DocCo',
        'slug' => 'docco-'.uniqid(),
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
        'employee_no' => 'DOC001',
        'name' => 'Test Employee',
        'status' => 'active',
    ]);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $visaType = DocumentType::query()->firstOrCreate(
        ['title' => 'Visa'],
        ['is_active' => true],
    );

    return compact('company', 'branch', 'employee', 'passportType', 'visaType');
}
