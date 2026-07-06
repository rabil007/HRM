<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeEducationQualification;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot create education qualifications', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => 'MBA',
    ])->assertRedirect(route('login'));
});

test('users without permission cannot manage education qualifications', function () {
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => 'MBA',
    ])->assertForbidden();
});

test('employee show page includes education qualifications', function () {
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $qualification = EmployeeEducationQualification::factory()
        ->forEmployee($employee)
        ->create([
            'certificate' => 'MBA',
            'issue_date' => '2024-06-01',
            'university' => 'State University',
            'country_id' => $country->id,
        ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page->component('organization/employee'),
            fn (Assert $page) => $page
                ->has('education_qualifications', 1)
                ->where('education_qualifications.0.id', $qualification->id)
                ->where('education_qualifications.0.certificate', 'MBA'),
        ));
});

test('users with permission can create education qualifications', function () {
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.education.manage']);

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => 'BSc Computer Science',
        'issue_date' => '2019-05-15',
        'university' => 'Tech Institute',
        'country_id' => $country->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_education_qualifications', [
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'certificate' => 'BSc Computer Science',
        'university' => 'Tech Institute',
        'country_id' => $country->id,
    ]);
});

test('creating education qualification requires certificate', function () {
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
            'employee_no' => 'EMP0002',
            'status' => 'active',
            'nationality_id' => $country->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.education.manage']);

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => '',
        'country_id' => $country->id,
    ])->assertSessionHasErrors('certificate');
});

test('education qualification rejects inactive countries', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $inactive = Country::query()->create([
        'code' => 'INV',
        'name' => 'Inactive',
        'dial_code' => '+998',
        'is_active' => false,
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
            'employee_no' => 'EMP0003',
            'status' => 'active',
            'nationality_id' => $country->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.education.manage']);

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => 'PhD',
        'country_id' => $inactive->id,
    ])->assertSessionHasErrors('country_id');
});

test('users can update and delete their education qualifications', function () {
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
            'employee_no' => 'EMP0004',
            'status' => 'active',
            'nationality_id' => $country->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $qualification = EmployeeEducationQualification::factory()
        ->forEmployee($employee)
        ->create([
            'certificate' => 'Diploma',
            'university' => 'Old School',
        ]);

    grantCompanyPermissions($user, $company, ['employees.education.manage']);

    $this->put(route('organization.employees.education.update', [$employee, $qualification]), [
        'certificate' => 'Diploma (Honors)',
        'issue_date' => '2021-01-01',
        'university' => 'New School',
        'country_id' => $country->id,
    ])->assertRedirect();

    $qualification->refresh();
    expect($qualification->certificate)->toBe('Diploma (Honors)');
    expect($qualification->university)->toBe('New School');

    $this->delete(route('organization.employees.education.destroy', [$employee, $qualification]))
        ->assertRedirect();

    expect(EmployeeEducationQualification::query()->find($qualification->id))->toBeNull();
});

test('education qualification routes reject mismatched employee', function () {
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
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    $qualification = EmployeeEducationQualification::factory()
        ->forEmployee($alice)
        ->create(['certificate' => 'Cert']);

    grantCompanyPermissions($user, $company, ['employees.education.manage']);

    $this->put(route('organization.employees.education.update', [$bob, $qualification]), [
        'certificate' => 'Hacked',
    ])->assertForbidden();

    $this->delete(route('organization.employees.education.destroy', [$bob, $qualification]))
        ->assertForbidden();
});
