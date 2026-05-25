<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
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
