<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Position;
use App\Models\User;
use App\Support\Settings\SettingKey;

test('guests cannot print employee salary certificate', function () {
    $employee = Employee::factory()->create();

    $this->get("/organization/employees/{$employee->id}/salary-certificate")
        ->assertRedirect(route('login'));
});

test('authenticated users can open printable salary certificate', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SCP',
        'name' => 'Salary Cert Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SCP',
        'name' => 'Salary Cert Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Overseas Marine Services Sole Proprietorship LLC',
        'slug' => 'oms-salary-cert',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'email' => 'hr@overseas-ms.com',
        'status' => 'active',
    ]);

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyName],
        ['value' => 'Overseas Marine Services Sole Proprietorship LLC', 'type' => 'string'],
    );

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::SupportEmail],
        ['value' => 'hr@overseas-ms.com', 'type' => 'string'],
    );

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
            'employee_no' => 'EMP-SC-01',
            'name' => 'Abdellah Bellymani',
            'emirates_id' => '01430057521379',
            'passport_number' => 'TB0906104',
            'nationality_id' => $nationality->id,
            'position_id' => $position->id,
            'hire_date' => '2026-02-02',
            'status' => 'active',
        ]);

    EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-02-02',
        'status' => 'active',
        'basic_salary' => 50,
        'housing_allowance' => 0,
        'transport_allowance' => 0,
        'other_allowances' => 0,
        'supplementary_allowance' => 0,
        'site_allowance' => 0,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/salary-certificate")
        ->assertSuccessful()
        ->assertSee('Subject: Salary Certificate.', false)
        ->assertSee('ABDELLAH BELLYMANI', false)
        ->assertSee('01430057521379', false)
        ->assertSee('TB0906104', false)
        ->assertSee('Macau', false)
        ->assertSee('Deck Officer', false)
        ->assertSee('Feb 02, 2026', false)
        ->assertSee('50.00 SCP', false)
        ->assertSee('Overseas Marine Services Sole Proprietorship LLC', false)
        ->assertSee('hr@overseas-ms.com', false)
        ->assertSee('View A4 PDF', false);
});

test('users can download salary certificate pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SC2',
        'name' => 'Salary Cert Land 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SC2',
        'name' => 'Salary Cert Currency 2',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Salary Cert Co',
        'slug' => 'salary-cert-co',
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
            'name' => 'Salary Employee',
            'status' => 'active',
        ]);

    EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'active',
        'basic_salary' => 1000,
        'housing_allowance' => 500,
        'supplementary_allowance' => 200,
        'site_allowance' => 100,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}/salary-certificate")
        ->assertSuccessful()
        ->assertSee('1300.00 SC2', false)
        ->assertDontSee('1500.00', false);

    $this->get("/organization/employees/{$employee->id}/salary-certificate?format=pdf&inline=1")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

test('users cannot print salary certificate for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SC3',
        'name' => 'Salary Cert Land 3',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SC3',
        'name' => 'Salary Cert Currency 3',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Allowed Co',
        'slug' => 'allowed-co-sc',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co-sc',
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

    $this->get("/organization/employees/{$employee->id}/salary-certificate")
        ->assertNotFound();
});
