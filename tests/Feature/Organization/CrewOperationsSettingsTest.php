<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewOperationsSetting;
use App\Models\Currency;
use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeCrewOperationsSettingsFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'COS',
        'name' => 'Crew Operations Settings Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'COS',
        'name' => 'Crew Operations Settings Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Operations Settings Co',
        'slug' => 'crew-operations-settings-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Settings Co',
        'slug' => 'other-settings-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'otherCompany');
}

test('guests cannot access crew operations settings', function () {
    $this->get(route('organization.crew-operations.settings.index'))
        ->assertRedirect(route('login'));
});

test('users without view permission cannot view crew operations settings', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.settings.index'))
        ->assertForbidden();
});

test('authorized users can view the crew operations settings index', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    $dept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Dept',
        'code' => 'CREW',
        'status' => 'active',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => [$dept->id],
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/settings')
            ->has('department_tree')
            ->has('crew_settings')
            ->where('crew_settings.pool_department_ids', [$dept->id])
        );
});

test('authorized user can update crew operations settings', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $dept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engine Crew',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [$dept->id],
        ])
        ->assertRedirect();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();

    expect($setting)->not->toBeNull()
        ->and($setting->pool_department_ids)->toBe([$dept->id]);
});

test('clearing pool department settings works', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $dept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Pool',
        'code' => 'POOL',
        'status' => 'active',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => [$dept->id],
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
        ])
        ->assertRedirect();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();

    expect($setting)->not->toBeNull()
        ->and($setting->pool_department_ids)->toBeNull();
});

test('users without update permission cannot change settings', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
        ])
        ->assertForbidden();
});

test('settings reject departments from another company', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $foreignDept = Department::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Dept',
        'code' => 'FOR',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [$foreignDept->id],
        ])
        ->assertSessionHasErrors(['pool_department_ids.0']);
});
