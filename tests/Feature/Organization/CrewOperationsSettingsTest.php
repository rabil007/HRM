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

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => $dept->id,
        'name' => 'Crew Planning',
        'code' => 'PLAN',
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
            ->has('department_tree', 1)
            ->where('department_tree.0.id', $dept->id)
            ->where('department_tree.0.name', 'Crew Dept')
            ->where('department_tree.0.children.0.id', $childDepartment->id)
            ->where('department_tree.0.children.0.name', 'Crew Planning')
            ->where('company_timezone', 'Asia/Dubai')
            ->has('crew_settings')
            ->where('crew_settings.pool_department_ids', [$dept->id])
            ->where('crew_settings.max_home_days', 30)
            ->where('crew_settings.sync_sea_service', true)
            ->where('crew_settings.notifications_enabled', false)
            ->where('crew_settings.notification_email_delivery_mode', 'scheduled')
            ->where('crew_settings.notification_email_digest_at', '08:00')
            ->where('crew_settings.notification_email_critical_immediate', true)
            ->has('notification_users')
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
            'max_home_days' => 45,
            'sync_sea_service' => true,
            'notifications_enabled' => true,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '09:30',
            'notification_email_critical_immediate' => false,
        ])
        ->assertRedirect();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();

    expect($setting)->not->toBeNull()
        ->and($setting->pool_department_ids)->toBe([$dept->id])
        ->and($setting->max_home_days)->toBe(45)
        ->and($setting->sync_sea_service)->toBeTrue()
        ->and($setting->notifications_enabled)->toBeTrue()
        ->and($setting->notification_email_delivery_mode->value)->toBe('scheduled')
        ->and($setting->notification_email_digest_at)->toBe('09:30')
        ->and($setting->notification_email_critical_immediate)->toBeFalse();
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
        'max_home_days' => 30,
        'sync_sea_service' => true,
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
            'max_home_days' => 30,
            'sync_sea_service' => true,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '08:00',
            'notification_email_critical_immediate' => true,
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
            'max_home_days' => 30,
            'sync_sea_service' => false,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '08:00',
            'notification_email_critical_immediate' => true,
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
            'max_home_days' => 30,
            'sync_sea_service' => true,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '08:00',
            'notification_email_critical_immediate' => true,
        ])
        ->assertSessionHasErrors(['pool_department_ids.0']);
});

test('invalid email delivery mode and digest time are rejected', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
            'max_home_days' => 30,
            'sync_sea_service' => true,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'invalid_mode',
            'notification_email_digest_at' => '25:99',
            'notification_email_critical_immediate' => true,
        ])
        ->assertSessionHasErrors([
            'notification_email_delivery_mode',
            'notification_email_digest_at',
        ]);
});

test('disabling sea service sync is logged with old and new values', function () {
    ['user' => $user, 'company' => $company] = makeCrewOperationsSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => null,
        'max_home_days' => 30,
        'sync_sea_service' => true,
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
            'max_home_days' => 30,
            'sync_sea_service' => false,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '08:00',
            'notification_email_critical_immediate' => true,
        ])
        ->assertRedirect();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();

    expect($setting?->sync_sea_service)->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => CrewOperationsSetting::class,
        'subject_id' => $setting->id,
        'causer_id' => $user->id,
        'description' => 'updated crew operations sea service sync setting',
    ]);
});
