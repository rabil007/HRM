<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeVaccination;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage vaccinations', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.vaccinations.store', $employee), [
        'vaccination_name' => 'COVID-19',
    ])->assertRedirect(route('login'));

    $this->get(route('organization.employees.vaccinations.import.template', $employee))->assertRedirect(route('login'));
});

test('users without permission cannot manage vaccinations', function () {
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
        'probation_end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.vaccinations.store', $employee), [
        'vaccination_name' => 'COVID-19',
    ])->assertForbidden();

    $this->get(route('organization.employees.vaccinations.import.template', $employee))->assertForbidden();
});

test('employee show page includes vaccinations', function () {
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
        'probation_end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $vaccination = EmployeeVaccination::factory()
        ->forEmployee($employee)
        ->create([
            'vaccination_name' => 'COVID-19',
            'country_id' => $country->id,
            'first_dose_date' => '2021-03-01',
        ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('vaccinations', 1)
            ->where('vaccinations.0.id', $vaccination->id)
            ->where('vaccinations.0.vaccination_name', 'COVID-19'));
});

test('users with permission can create update and delete vaccinations', function () {
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
            'name' => 'Jane Doe',
            'nationality_id' => $country->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.vaccination.manage']);

    $this->post(route('organization.employees.vaccinations.store', $employee), [
        'vaccination_name' => 'Hepatitis B',
        'country_id' => $country->id,
        'first_dose_date' => '2020-01-15',
        'second_dose_date' => '2020-07-15',
        'booster_dose_date' => null,
    ])->assertRedirect();

    $row = EmployeeVaccination::query()
        ->where('employee_id', $employee->id)
        ->where('vaccination_name', 'Hepatitis B')
        ->first();

    expect($row)->not->toBeNull();

    $this->put(route('organization.employees.vaccinations.update', [
        'employee' => $employee,
        'vaccination' => $row,
    ]), [
        'vaccination_name' => 'Hepatitis B (series)',
        'country_id' => null,
        'first_dose_date' => '2020-01-15',
        'second_dose_date' => '2020-07-15',
        'booster_dose_date' => null,
    ])->assertRedirect();

    expect($row->fresh()->vaccination_name)->toBe('Hepatitis B (series)');

    $this->delete(route('organization.employees.vaccinations.destroy', [
        'employee' => $employee,
        'vaccination' => $row,
    ]))->assertRedirect();

    expect(EmployeeVaccination::query()->find($row->id))->toBeNull();
});

test('csv import appends vaccination rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->firstOrCreate(
        ['code' => 'UAE'],
        [
            'name' => 'United Arab Emirates',
            'dial_code' => '+971',
            'is_active' => true,
        ],
    );

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
            'name' => 'Importer',
            'nationality_id' => $country->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.vaccination.manage']);

    $csv = <<<'CSV'
Vaccination,Country,1st dose,2nd dose,Booster
Yellow Fever,United Arab Emirates,2023-01-10,,2024-01-10

CSV;

    $file = UploadedFile::fake()->createWithContent('vac.csv', $csv);

    $this->post(route('organization.employees.vaccinations.import', $employee), [
        'file' => $file,
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_vaccinations', [
        'employee_id' => $employee->id,
        'vaccination_name' => 'Yellow Fever',
        'country_id' => $country->id,
    ]);
});

test('another employee cannot mutate vaccination rows', function () {
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

    $alice = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-A',
            'status' => 'active',
            'nationality_id' => $country->id,
        ]);

    $bob = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-B',
            'status' => 'active',
            'nationality_id' => $country->id,
        ]);

    foreach ([$alice, $bob] as $e) {
        EmployeeContract::query()->create([
            'company_id' => $company->id,
            'employee_id' => $e->id,
            'contract_type' => 'unlimited',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    $vaccination = EmployeeVaccination::factory()
        ->forEmployee($alice)
        ->create(['vaccination_name' => 'Flu']);

    grantCompanyPermissions($user, $company, ['employees.vaccination.manage']);

    $this->put(route('organization.employees.vaccinations.update', [
        'employee' => $bob,
        'vaccination' => $vaccination,
    ]), [
        'vaccination_name' => 'Hacked',
        'country_id' => null,
    ])->assertForbidden();

    $this->delete(route('organization.employees.vaccinations.destroy', [
        'employee' => $bob,
        'vaccination' => $vaccination,
    ]))->assertForbidden();
});
