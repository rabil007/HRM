<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage employee contracts', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertRedirect(route('login'));
});

test('users without permission cannot manage employee contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECT',
        'name' => 'Contract Testland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECT',
        'name' => 'Contract Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Co',
        'slug' => 'contract-co',
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
            'employee_no' => 'EMP-C01',
            'name' => 'Contract Worker',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertForbidden();
});

test('employee show page includes contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECS',
        'name' => 'Contract Show Land',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECS',
        'name' => 'Contract Show Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Show Co',
        'slug' => 'contract-show-co',
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
            'employee_no' => 'EMP-C02',
            'name' => 'Show Contracts',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'limited',
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'probation_end_date' => null,
        'labor_contract_id' => 'LC-100',
        'status' => 'ended',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('contracts')
            ->where(
                'contracts',
                fn ($contracts) => collect($contracts)->contains(
                    fn ($row) => $row['contract_type'] === 'limited' && $row['status'] === 'ended',
                ),
            ));
});

test('users with permission can add update and delete contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECM',
        'name' => 'Contract Manage Land',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECM',
        'name' => 'Contract Manage Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Manage Co',
        'slug' => 'contract-manage-co',
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
            'employee_no' => 'EMP-C03',
            'name' => 'Manage Contracts',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.contracts.manage']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2024-06-01',
        'status' => 'ended',
        'basic_salary' => 5000,
    ])->assertRedirect();

    $ended = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'ended')
        ->latest('id')
        ->first();
    expect($ended)->not->toBeNull();

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'limited',
        'start_date' => '2026-01-01',
        'end_date' => '2027-01-01',
        'status' => 'active',
        'basic_salary' => 8000,
    ])->assertRedirect();

    $active = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($active)->not->toBeNull()
        ->and($active->contract_type)->toBe('limited');

    $this->put(route('organization.employees.contracts.update', [$employee, $active]), [
        'contract_type' => 'limited',
        'start_date' => '2026-01-01',
        'end_date' => '2028-01-01',
        'status' => 'active',
        'basic_salary' => 9000,
    ])->assertRedirect();

    expect($active->fresh()->basic_salary)->toBe('9000.00');

    $this->delete(route('organization.employees.contracts.destroy', [$employee, $active]))
        ->assertRedirect();

    $this->assertDatabaseMissing('employee_contracts', ['id' => $active->id]);
});

test('activating a contract ends other active contracts for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECA',
        'name' => 'Contract Active Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECA',
        'name' => 'Contract Active Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Active Co',
        'slug' => 'contract-active-co',
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
            'employee_no' => 'EMP-C04',
            'name' => 'Active Contract',
            'status' => 'active',
        ]);

    $first = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2025-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.contracts.manage']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'limited',
        'start_date' => '2026-05-01',
        'status' => 'active',
    ])->assertRedirect();

    expect($first->fresh()->status)->toBe('ended');
});
