<?php

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\User;

function makeBankAccountExportFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Export Land', 'dial_code' => '+904', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Export Currency', 'symbol' => 'E$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'ExportCo',
        'slug' => 'exportco-'.uniqid(),
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
        'employee_no' => 'EXP001',
        'name' => 'Export Employee',
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Export Bank',
        'is_active' => true,
    ]);

    $bankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE112233445566',
        'account_name' => 'Export Employee Account',
        'is_primary' => true,
    ]);

    return compact('company', 'branch', 'employee', 'bank', 'bankAccount');
}

test('guests cannot access bank accounts export', function () {
    $this->get(route('organization.bank-accounts.export'))->assertRedirect(route('login'));
});

test('users without bank accounts view permission cannot export bank accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeBankAccountExportFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.bank-accounts.export'))->assertForbidden();
});

test('authenticated users with permission can export bank accounts as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeBankAccountExportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->get(route('organization.bank-accounts.export', ['format' => 'csv']))->assertOk();
    $this->get(route('organization.bank-accounts.export', ['format' => 'xlsx']))->assertOk();
    $this->get(route('organization.bank-accounts.export', ['format' => 'pdf']))->assertOk();
});

test('export respects primary filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'bank' => $bank] = makeBankAccountExportFixtures();

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE998877665544',
        'account_name' => 'Secondary Account',
        'is_primary' => false,
    ]);

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->get(route('organization.bank-accounts.export', ['format' => 'csv', 'is_primary' => 'primary']))
        ->assertOk();

    $this->get(route('organization.bank-accounts.export', ['format' => 'csv', 'is_primary' => 'secondary']))
        ->assertOk();
});
