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

test('employee create renders profile shell without requiring templates', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CR',
        'name' => 'Create Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRC',
        'name' => 'Create Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Create Co',
        'slug' => 'create-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->actingAs($user)
        ->get('/organization/employees/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('mode', 'create')
            ->has('employee_tabs')
            ->where('employee_tabs.personal', true));
});

test('employee create respects profile template tab visibility', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CR2',
        'name' => 'Create Land 2',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CR2',
        'name' => 'Create Currency 2',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Create Co 2',
        'slug' => 'create-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['tabs']['bank']['visible'] = false;
    $configuration['tabs']['education']['visible'] = false;

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'No bank',
        'configuration_json' => $configuration,
    ]);

    $this->actingAs($user)
        ->get("/organization/employees/create?profile_template_id={$template->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('mode', 'create')
            ->where('employee_tabs.bank', false)
            ->where('employee_tabs.education', false)
            ->where('employee_tabs.personal', true));
});

test('employee create profile_fields excludes hidden personal fields such as nearest airport', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CR3',
        'name' => 'Create Land 3',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CR3',
        'name' => 'Create Currency 3',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Create Co 3',
        'slug' => 'create-co-3',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $visibleKeys = array_keys(
        EmployeeProfileTemplateFieldRegistry::defaultConfiguration()['fields']['employees'],
    );
    $visibleKeys = array_values(array_diff($visibleKeys, ['nearest_airport']));

    $template = createEmployeeProfileTemplate(
        $company,
        'No nearest airport',
        employeeProfileTemplateWithVisibleEmployeeFields($visibleKeys),
    );

    $response = $this->actingAs($user)
        ->get("/organization/employees/create?profile_template_id={$template->id}");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organization/employee')
        ->has('employee_tabs.profile_fields'));

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toBeArray()
        ->and($profileFields)->toContain('address')
        ->and($profileFields)->not->toContain('nearest_airport');
});
