<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function profileTemplateCompany(): Company
{
    $country = Country::query()->create([
        'code' => 'PT',
        'name' => 'Profile Template Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PTC',
        'name' => 'Profile Template Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Profile Template Co',
        'slug' => 'profile-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('users with permission can list employee profile templates', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    grantCompanyPermissions($user, $company, ['employee_profile_templates.view']);

    EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine crew',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $this->actingAs($user)
        ->get('/organization/templates/employee-profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/templates/employee-profile/index')
            ->has('templates', 1)
            ->where('templates.0.name', 'Marine crew'));
});

test('users can store employee profile templates', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.create',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['tabs']['bank']['visible'] = false;

    $this->actingAs($user)
        ->post('/organization/templates/employee-profile', [
            'name' => 'Office staff',
            'description' => 'No bank tab',
            'is_active' => true,
            'configuration_json' => json_encode($configuration),
        ])
        ->assertRedirect(route('organization.employee-profile-templates.index'));

    expect(EmployeeProfileTemplate::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('assigned profile template cannot be deleted', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Assigned template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.delete',
    ]);

    $this->actingAs($user)
        ->from(route('organization.employee-profile-templates.index'))
        ->delete(route('organization.employee-profile-templates.destroy', $template))
        ->assertRedirect(route('organization.employee-profile-templates.index'))
        ->assertSessionHasErrors('employee_profile_template');

    expect(EmployeeProfileTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
});

test('failed profile template deletion does not change assigned employee', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Protected template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.delete',
    ]);

    $this->actingAs($user)
        ->from(route('organization.employee-profile-templates.index'))
        ->delete(route('organization.employee-profile-templates.destroy', $template))
        ->assertSessionHasErrors('employee_profile_template');

    expect($employee->fresh()->employee_profile_template_id)->toBe($template->id);
});

test('unused profile template can be deleted normally', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Unused template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.delete',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.employee-profile-templates.destroy', $template))
        ->assertRedirect(route('organization.employee-profile-templates.index'))
        ->assertSessionHas('success', 'Employee profile template deleted successfully.');

    expect(EmployeeProfileTemplate::query()->whereKey($template->id)->exists())->toBeFalse()
        ->and(EmployeeProfileTemplate::withTrashed()->whereKey($template->id)->exists())->toBeTrue();
});

test('profile template can be deleted when only soft-deleted employees reference it', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Historical template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);
    $employee->delete();

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.delete',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.employee-profile-templates.destroy', $template))
        ->assertRedirect(route('organization.employee-profile-templates.index'))
        ->assertSessionHas('success', 'Employee profile template deleted successfully.');

    expect(EmployeeProfileTemplate::query()->whereKey($template->id)->exists())->toBeFalse();
});

test('assigned profile template can be deactivated while remaining on employees', function () {
    $user = User::factory()->create();
    $company = profileTemplateCompany();

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Deactivatable template',
        'configuration_json' => $configuration,
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, [
        'employee_profile_templates.view',
        'employee_profile_templates.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.employee-profile-templates.update', $template), [
            'name' => 'Deactivatable template',
            'description' => null,
            'is_active' => false,
            'configuration_json' => json_encode($configuration),
        ])
        ->assertRedirect(route('organization.employee-profile-templates.index'))
        ->assertSessionHas('success', 'Employee profile template updated successfully.');

    expect($template->fresh()->is_active)->toBeFalse()
        ->and($employee->fresh()->employee_profile_template_id)->toBe($template->id);
});
