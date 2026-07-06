<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;

test('guests cannot print employee salary declaration', function () {
    $employee = Employee::factory()->create();

    $this->get("/organization/employees/{$employee->id}/salary-declaration")
        ->assertRedirect(route('login'));
});

test('authenticated users can open printable salary declaration with employee data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SDP',
        'name' => 'Salary Decl Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SDP',
        'name' => 'Salary Decl Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Overseas Marine Services Sole Proprietorship LLC',
        'slug' => 'oms-salary-decl',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $nationality = Country::query()->create([
        'code' => 'MO',
        'name' => 'Macau',
        'dial_code' => '+853',
        'is_active' => true,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Deck Officer',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-SD-01',
            'name' => 'Abdellah Bellymani',
            'emirates_id' => '01430057521379',
            'passport_number' => 'TB0906104',
            'nationality_id' => $nationality->id,
            'position_id' => $position->id,
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/salary-declaration")
        ->assertSuccessful()
        ->assertSee('Employee Declaration and Acknowledgment', false)
        ->assertSee('إقرار وموافقة الموظف', false)
        ->assertSee('Abdellah Bellymani', false)
        ->assertSee('01430057521379', false)
        ->assertSee('Macau', false)
        ->assertSee('Deck Officer', false);
});

test('salary declaration falls back to passport number when emirates id missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SD2',
        'name' => 'Salary Decl Land 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SD2',
        'name' => 'Salary Decl Currency 2',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Salary Decl Co',
        'slug' => 'salary-decl-co',
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
            'name' => 'No Eid Employee',
            'emirates_id' => null,
            'passport_number' => 'PP-9988',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/salary-declaration")
        ->assertSuccessful()
        ->assertSee('PP-9988', false);
});

test('users cannot print salary declaration for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SD3',
        'name' => 'Salary Decl Land 3',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SD3',
        'name' => 'Salary Decl Currency 3',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Allowed Co Decl',
        'slug' => 'allowed-co-sd',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Co Decl',
        'slug' => 'other-co-sd',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'name' => 'Hidden Employee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/salary-declaration")
        ->assertNotFound();
});
