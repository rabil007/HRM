<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Support\Employees\ActiveEmployeeConstraint;

function makeActiveEmployeeConstraintCompany(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => $suffix,
        'name' => "AEC Country {$suffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $suffix,
        'name' => "AEC Currency {$suffix}",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => "AEC {$suffix}",
        'slug' => 'aec-'.strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('apply keeps only active employees in the given company', function () {
    $company = makeActiveEmployeeConstraintCompany('A1');
    $other = makeActiveEmployeeConstraintCompany('A2');

    $active = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    Employee::factory()->forCompany($company)->inactive()->create();
    Employee::factory()->forCompany($company)->terminated()->create();
    Employee::factory()->forCompany($other)->create(['status' => 'active']);

    $ids = ActiveEmployeeConstraint::apply(Employee::query(), $company->id)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$active->id]);
});

test('whereHas excludes related records whose employee is inactive terminated or foreign', function () {
    $company = makeActiveEmployeeConstraintCompany('B1');
    $other = makeActiveEmployeeConstraintCompany('B2');

    $active = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $inactive = Employee::factory()->forCompany($company)->inactive()->create();
    $foreign = Employee::factory()->forCompany($other)->create(['status' => 'active']);

    foreach ([$active, $inactive, $foreign] as $employee) {
        EmployeeBankAccount::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'account_name' => $employee->name,
            'iban' => 'AE'.$employee->id,
            'is_primary' => true,
        ]);
    }

    $ids = ActiveEmployeeConstraint::whereHas(EmployeeBankAccount::query(), $company->id)
        ->pluck('employee_id')
        ->all();

    expect($ids)->toBe([$active->id]);
});
