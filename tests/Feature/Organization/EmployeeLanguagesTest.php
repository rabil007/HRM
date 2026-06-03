<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLanguage;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage languages', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.languages.store', $employee), [
        'language_name' => 'English',
    ])->assertRedirect(route('login'));
});

test('users without permission cannot manage languages', function () {
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

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.languages.store', $employee), [
        'language_name' => 'English',
    ])->assertForbidden();
});

test('employee show page includes languages', function () {
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
            'nationality_id' => $country->id,
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

    EmployeeLanguage::factory()
        ->forEmployee($employee)
        ->create([
            'language_name' => 'Hindi',
            'is_spoken' => true,
            'is_mother_tongue' => true,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page->component('organization/employee'),
            fn (Assert $page) => $page
                ->has('languages', 1)
                ->where('languages.0.language_name', 'Hindi'),
        ));
});

test('users with permission can add update and delete languages', function () {
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

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.languages.manage']);

    $this->post(route('organization.employees.languages.store', $employee), [
        'language_name' => 'English (US)',
        'is_spoken' => true,
        'is_written' => true,
        'is_understood' => true,
        'is_mother_tongue' => false,
    ])->assertRedirect();

    $row = EmployeeLanguage::query()->where('employee_id', $employee->id)->first();

    expect($row)->not->toBeNull();
    expect($row->is_spoken)->toBeTrue();
    expect($row->is_mother_tongue)->toBeFalse();

    $this->put(route('organization.employees.languages.update', [$employee, $row]), [
        'language_name' => 'English (US)',
        'is_spoken' => false,
        'is_written' => true,
        'is_understood' => true,
        'is_mother_tongue' => true,
    ])->assertRedirect();

    expect($row->fresh()->is_mother_tongue)->toBeTrue()
        ->and($row->fresh()->is_spoken)->toBeFalse();

    $this->delete(route('organization.employees.languages.destroy', [$employee, $row]))->assertRedirect();

    $this->assertSoftDeleted('employee_languages', ['id' => $row->id]);
});
