<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage bank accounts', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.bank-accounts.store', $employee), [
        'bank_id' => null,
        'iban' => 'AE123',
        'account_name' => 'Test',
        'is_primary' => true,
    ])->assertRedirect(route('login'));
});

test('users without permission cannot manage bank accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Test Bank',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.bank-accounts.store', $employee), [
        'bank_id' => $bank->id,
        'iban' => 'AE123',
        'account_name' => 'Holder',
        'is_primary' => true,
    ])->assertForbidden();
});

test('employee show includes bank_accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Test Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE029010101010',
        'account_name' => 'John Doe',
        'is_primary' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('bank_accounts', 1)
            ->where('bank_accounts.0.iban', 'AE029010101010')
            ->where('bank_accounts.0.account_name', 'John Doe'));
});

test('users with permission can add update and delete bank accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $bankA = Bank::query()->create(['name' => 'Bank A', 'is_active' => true]);
    $bankB = Bank::query()->create(['name' => 'Bank B', 'is_active' => true]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.bank_accounts.manage']);

    $this->post(route('organization.employees.bank-accounts.store', $employee), [
        'bank_id' => $bankA->id,
        'iban' => 'AE111',
        'account_name' => 'Primary holder',
        'is_primary' => false,
    ])->assertRedirect();

    $row = EmployeeBankAccount::query()->where('employee_id', $employee->id)->first();

    expect($row)->not->toBeNull();
    expect($row->is_primary)->toBeTrue();

    $this->post(route('organization.employees.bank-accounts.store', $employee), [
        'bank_id' => $bankB->id,
        'iban' => 'AE222',
        'account_name' => 'Second',
        'is_primary' => true,
    ])->assertRedirect();

    expect($row->fresh()->is_primary)->toBeFalse();
    expect(EmployeeBankAccount::query()->where('employee_id', $employee->id)->where('is_primary', true)->count())->toBe(1);

    $secondary = EmployeeBankAccount::query()
        ->where('employee_id', $employee->id)
        ->where('iban', 'AE222')
        ->first();

    expect($secondary?->is_primary)->toBeTrue();

    $this->put(route('organization.employees.bank-accounts.update', [$employee, $row]), [
        'bank_id' => $bankA->id,
        'iban' => 'AE111-updated',
        'account_name' => 'Primary holder',
        'is_primary' => true,
    ])->assertRedirect();

    expect($row->fresh()->iban)->toBe('AE111-updated')
        ->and($row->fresh()->is_primary)->toBeTrue()
        ->and($secondary->fresh()->is_primary)->toBeFalse();

    $this->delete(route('organization.employees.bank-accounts.destroy', [$employee, $secondary]))->assertRedirect();

    $this->assertDatabaseMissing('employee_bank_accounts', ['id' => $secondary->id]);

    expect($row->fresh()->is_primary)->toBeTrue();
});
