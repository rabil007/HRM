<?php

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeProfileTemplate;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Inertia\Testing\AssertableInertia as Assert;

function makeBankAccountBrowseFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'BK1'],
        ['name' => 'Bank Test Land', 'dial_code' => '+902', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'BK1'],
        ['name' => 'Bank Test Currency', 'symbol' => 'B$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'BankCo',
        'slug' => 'bankco-'.uniqid(),
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
        'employee_no' => 'BNK001',
        'name' => 'Bank Employee',
        'status' => 'active',
    ]);

    return compact('company', 'branch', 'employee');
}

test('guests cannot access employee bank accounts browse page', function () {
    ['employee' => $employee] = makeBankAccountBrowseFixtures();

    $this->get(route('organization.bank-accounts.employee', $employee))
        ->assertRedirect(route('login'));
});

test('users without bank accounts view cannot access employee bank accounts browse page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeBankAccountBrowseFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.bank-accounts.employee', $employee))->assertForbidden();
});

test('employee bank accounts browse page loads bank accounts and template fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeBankAccountBrowseFixtures();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Bank Accounts',
        'description' => null,
        'is_active' => true,
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee->update(['employee_profile_template_id' => $template->id]);

    grantCompanyPermissions($user, $company, [
        'bank_accounts.view',
        'bank_accounts.create',
        'bank_accounts.update',
        'bank_accounts.delete',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Standard Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE1234567890',
        'account_name' => 'Bank Employee',
        'is_primary' => true,
    ]);

    $this->get(route('organization.bank-accounts.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/employee')
            ->where('employee.id', $employee->id)
            ->where('employee.name', 'Bank Employee')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE1234567890')
            ->has('template_bank_account_fields.iban')
            ->where('can.view', true)
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true)
            ->has('back'));
});
