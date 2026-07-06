<?php

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeNoBankAccountFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'BK3'],
        ['name' => 'No Bank Land', 'dial_code' => '+904', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'BK3'],
        ['name' => 'No Bank Currency', 'symbol' => 'B$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'NoBankCo',
        'slug' => 'nobankco-'.uniqid(),
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

    $employeeNoAccount = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'NOB001',
        'name' => 'No Account Employee',
        'status' => 'active',
    ]);

    $employeeWithAccount = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'NOB002',
        'name' => 'Has Account Employee',
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'No Account Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeWithAccount->id,
        'bank_id' => $bank->id,
        'iban' => 'AE1122334455',
        'account_name' => 'Has Account Employee',
        'is_primary' => true,
    ]);

    return compact('company', 'branch', 'employeeNoAccount', 'employeeWithAccount');
}

test('guests cannot access no-account index', function () {
    $this->get(route('organization.bank-accounts.no-account'))->assertRedirect(route('login'));
});

test('users without bank accounts view cannot access no-account index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeNoBankAccountFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.bank-accounts.no-account'))->assertForbidden();
});

test('no-account index lists active employees without any bank accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employeeNoAccount' => $employeeNoAccount] = makeNoBankAccountFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->get(route('organization.bank-accounts.no-account'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/no-account')
            ->has('employees', 1)
            ->where('employees.0.id', $employeeNoAccount->id)
            ->where('employees.0.name', 'No Account Employee'));
});
