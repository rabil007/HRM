<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeWorkExperience;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage work experience', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.work-experience.store', $employee), [
        'company_name' => 'Acme',
        'job_title' => 'Tech',
        'date_from' => '2024-01-01',
    ])->assertRedirect(route('login'));

    $this->get(route('organization.employees.work-experience.import.template', $employee))->assertRedirect(route('login'));
});

test('users without permission cannot manage work experience', function () {
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

    $this->post(route('organization.employees.work-experience.store', $employee), [
        'company_name' => 'Acme',
        'job_title' => 'Tech',
        'date_from' => '2024-01-01',
    ])->assertForbidden();

    $this->get(route('organization.employees.work-experience.import.template', $employee))->assertForbidden();

    $file = UploadedFile::fake()->createWithContent(
        'we.csv',
        "company_name,job_title,date_from\nBeta,Lead,2020-06-01\n",
    );

    $this->post(route('organization.employees.work-experience.import', $employee), [
        'file' => $file,
    ])->assertForbidden();
});

test('employee show page includes work experience rows', function () {
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

    $experience = EmployeeWorkExperience::factory()
        ->forEmployee($employee)
        ->create([
            'company_name' => 'OMS',
            'job_title' => 'Engineer',
            'date_from' => '2025-03-01',
            'date_to' => null,
            'responsibility' => 'Ship features',
            'sort_order' => 0,
        ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('work_experiences', 1)
            ->where('work_experiences.0.id', $experience->id)
            ->where('work_experiences.0.company_name', 'OMS')
            ->where('work_experiences.0.job_title', 'Engineer'));
});

test('users with permission can create update and delete work experience', function () {
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
        'end_date' => null,
        'probation_end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.work_experience.manage']);

    $this->post(route('organization.employees.work-experience.store', $employee), [
        'company_name' => 'Gamma LLC',
        'job_title' => 'Manager',
        'date_from' => '2021-01-01',
        'date_to' => '2023-12-31',
        'responsibility' => 'Operations',
    ])->assertRedirect();

    $experience = EmployeeWorkExperience::query()
        ->where('employee_id', $employee->id)
        ->where('company_name', 'Gamma LLC')
        ->first();

    expect($experience)->not->toBeNull();

    $this->put(route('organization.employees.work-experience.update', [$employee, $experience]), [
        'company_name' => 'Gamma Holdings',
        'job_title' => 'Director',
        'date_from' => '2021-01-01',
        'date_to' => '2023-12-31',
        'responsibility' => null,
    ])->assertRedirect();

    expect($experience->fresh()->company_name)->toBe('Gamma Holdings');

    $this->delete(route('organization.employees.work-experience.destroy', [$employee, $experience]))
        ->assertRedirect();

    expect(EmployeeWorkExperience::query()->find($experience->id))->toBeNull();
});

test('csv import appends rows for the employee', function () {
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

    grantCompanyPermissions($user, $company, ['employees.work_experience.manage']);

    $csv = <<<'CSV'
company name,job title,start date,end date,responsibilities
North Co,Consultant,"Jan 15, 2019","Dec 31, 2022",Deliver oilfield projects

CSV;

    $file = UploadedFile::fake()->createWithContent('bulk.csv', $csv);

    $this->post(route('organization.employees.work-experience.import', $employee), [
        'file' => $file,
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_work_experiences', [
        'employee_id' => $employee->id,
        'company_name' => 'North Co',
        'job_title' => 'Consultant',
    ]);

    expect(EmployeeWorkExperience::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('another employee cannot mutate work experience rows', function () {
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

    foreach ([$alice, $bob] as $row) {
        EmployeeContract::query()->create([
            'company_id' => $company->id,
            'employee_id' => $row->id,
            'contract_type' => 'unlimited',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    $experience = EmployeeWorkExperience::factory()
        ->forEmployee($alice)
        ->create(['company_name' => 'Held', 'job_title' => 'Role', 'date_from' => '2025-01-01']);

    grantCompanyPermissions($user, $company, ['employees.work_experience.manage']);

    $this->put(route('organization.employees.work-experience.update', [
        'employee' => $bob,
        'workExperience' => $experience,
    ]), [
        'company_name' => 'Hacked',
        'job_title' => 'No',
        'date_from' => '2025-01-01',
    ])->assertForbidden();

    $this->delete(route('organization.employees.work-experience.destroy', [
        'employee' => $bob,
        'workExperience' => $experience,
    ]))
        ->assertForbidden();
});
