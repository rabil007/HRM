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

function makeBankAccountIndexFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'BK2'],
        ['name' => 'Bank Index Land', 'dial_code' => '+903', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'BK2'],
        ['name' => 'Bank Index Currency', 'symbol' => 'B$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'BankIndexCo',
        'slug' => 'bankindexco-'.uniqid(),
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
        'employee_no' => 'BNK002',
        'name' => 'Index Employee',
        'status' => 'active',
    ]);

    return compact('company', 'branch', 'employee');
}

test('guests cannot access bank accounts index', function () {
    $this->get(route('organization.bank-accounts'))->assertRedirect(route('login'));
});

test('users with employees view but without bank accounts view cannot access bank accounts module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeBankAccountIndexFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.bank-accounts'))->assertForbidden();
});

test('bank accounts index returns paginated bank accounts with summary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeBankAccountIndexFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $bank = Bank::query()->create([
        'name' => 'Index Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE9988776655',
        'account_name' => 'Index Employee',
        'is_primary' => true,
    ]);

    $this->get(route('organization.bank-accounts'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('summary.total_bank_accounts', 1)
            ->where('summary.primary_accounts', 1)
            ->where('summary.secondary_accounts', 0)
            ->where('summary.no_account_employees', 0)
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.employee_name', 'Index Employee')
            ->where('bank_accounts.0.bank_name', 'Index Bank')
            ->where('can.view', true)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false));
});

test('bank accounts index filters by primary and secondary status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeBankAccountIndexFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $bank = Bank::query()->create([
        'name' => 'Filter Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE1111111111',
        'account_name' => 'Primary Account',
        'is_primary' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE2222222222',
        'account_name' => 'Secondary Account',
        'is_primary' => false,
    ]);

    $this->get(route('organization.bank-accounts', ['is_primary' => 'primary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('is_primary', 'primary')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE1111111111'));

    $this->get(route('organization.bank-accounts', ['is_primary' => 'secondary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('is_primary', 'secondary')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE2222222222'));

    $this->get(route('organization.bank-accounts', ['is_primary' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('is_primary', 'primary')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE1111111111'));

    $this->get(route('organization.bank-accounts', ['is_primary' => '0']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('is_primary', 'secondary')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE2222222222'));
});
