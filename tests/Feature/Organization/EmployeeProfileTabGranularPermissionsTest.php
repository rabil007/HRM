<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\User;

/**
 * @return array{user: User, company: Company, employee: Employee, country: Country}
 */
function makeProfileTabPermissionFixtures(string $slugSuffix): array
{
    $user = User::factory()->create();
    $code = strtoupper(substr($slugSuffix, 0, 3)).fake()->unique()->numerify('#');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Profile Tab Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Profile Tab Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Profile Tab Co',
        'slug' => 'profile-tab-'.$slugSuffix.'-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Tab Employee',
    ]);
    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'country');
}

test('education create permission alone cannot update or delete', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'country' => $country] = makeProfileTabPermissionFixtures('edu');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view', 'education.create']);

    $qualification = EmployeeEducationQualification::factory()
        ->forEmployee($employee)
        ->create(['certificate' => 'Diploma']);

    $this->post(route('organization.employees.education.store', $employee), [
        'certificate' => 'BSc',
        'country_id' => $country->id,
    ])->assertRedirect();

    $this->put(route('organization.employees.education.update', [$employee, $qualification]), [
        'certificate' => 'Hacked',
        'country_id' => $country->id,
    ])->assertForbidden();

    $this->delete(route('organization.employees.education.destroy', [$employee, $qualification]))
        ->assertForbidden();
});

test('work experience update permission alone cannot create import or delete', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeProfileTabPermissionFixtures('wex');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view', 'work_experience.update']);

    $row = EmployeeWorkExperience::factory()
        ->forEmployee($employee)
        ->create([
            'company_name' => 'Old Co',
            'job_title' => 'Deck',
        ]);

    $this->post(route('organization.employees.work-experience.store', $employee), [
        'company_name' => 'New Co',
        'job_title' => 'Engineer',
        'date_from' => '2024-01-01',
    ])->assertForbidden();

    $this->get(route('organization.employees.work-experience.import.template', $employee))
        ->assertForbidden();

    $this->put(route('organization.employees.work-experience.update', [$employee, $row]), [
        'company_name' => 'Updated Co',
        'job_title' => 'Deck Officer',
        'date_from' => '2024-01-01',
    ])->assertRedirect();

    $this->delete(route('organization.employees.work-experience.destroy', [$employee, $row]))
        ->assertForbidden();
});

test('vaccination delete permission alone cannot create or update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'country' => $country] = makeProfileTabPermissionFixtures('vac');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view', 'vaccination.delete']);

    $row = EmployeeVaccination::factory()
        ->forEmployee($employee)
        ->create(['vaccination_name' => 'Yellow Fever']);

    $this->post(route('organization.employees.vaccinations.store', $employee), [
        'vaccination_name' => 'COVID',
        'country_id' => $country->id,
    ])->assertForbidden();

    $this->put(route('organization.employees.vaccinations.update', [$employee, $row]), [
        'vaccination_name' => 'Hacked',
        'country_id' => $country->id,
    ])->assertForbidden();

    $this->delete(route('organization.employees.vaccinations.destroy', [$employee, $row]))
        ->assertRedirect();
});

test('languages update permission alone cannot create or delete', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeProfileTabPermissionFixtures('lng');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view', 'languages.update']);

    $row = EmployeeLanguage::factory()
        ->forEmployee($employee)
        ->create(['language_name' => 'Arabic']);

    $this->post(route('organization.employees.languages.store', $employee), [
        'language_name' => 'English',
    ])->assertForbidden();

    $this->put(route('organization.employees.languages.update', [$employee, $row]), [
        'language_name' => 'Arabic (MSA)',
        'is_spoken' => true,
        'is_written' => true,
        'is_understood' => true,
        'is_mother_tongue' => false,
    ])->assertRedirect();

    $this->delete(route('organization.employees.languages.destroy', [$employee, $row]))
        ->assertForbidden();
});

test('employee profile can flags expose granular education permissions', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeProfileTabPermissionFixtures('can');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'education.create',
        'education.delete',
    ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('can.education_create', true)
            ->where('can.education_update', false)
            ->where('can.education_delete', true)
            ->where('can.work_experience_create', false)
            ->where('can.vaccination_import', false)
            ->where('can.languages_create', false));
});
