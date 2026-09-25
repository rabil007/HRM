<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewOperationsSetting;
use App\Models\Currency;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsSettings;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function makeFutureMovementSettingsFixtures(): array
{
    $user = User::factory()->create();

    $suffix = strtoupper(substr(uniqid(), -4));

    $country = Country::query()->create([
        'code' => 'F'.$suffix,
        'name' => 'Future Movement Dates Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'F'.$suffix,
        'name' => 'Future Movement Dates Currency '.$suffix,
        'symbol' => 'F$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Future Movement Dates Co',
        'slug' => 'future-movement-dates-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

function futureMovementSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'pool_department_ids' => [],
        'max_home_days' => 30,
        'sync_sea_service' => true,
        'sync_training_to_employee_training' => false,
        'allow_future_actual_movement_dates' => false,
        'notifications_enabled' => false,
        'notification_recipient_user_ids' => [],
        'alert_signoff_overdue' => true,
        'alert_signoff_no_relief' => true,
        'alert_relief_not_ready' => true,
        'alert_current_manning_gap' => true,
        'alert_projected_manning_gap' => true,
        'notification_email_delivery_mode' => 'scheduled',
        'notification_email_digest_at' => '08:00',
        'notification_email_critical_immediate' => false,
    ], $overrides);
}

test('allow future actual movement dates defaults to disabled without settings row', function () {
    ['company' => $company] = makeFutureMovementSettingsFixtures();

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse()
        ->and(CrewOperationsSettings::CONFIG_ALLOW_FUTURE_ACTUAL_MOVEMENT_DATES)
        ->toBe('crew_operations.allow_future_actual_movement_dates');
});

test('allow future actual movement dates remains disabled when settings row is false', function () {
    ['company' => $company] = makeFutureMovementSettingsFixtures();

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => false,
    ]);

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse();
});

test('authorized users can enable allow future actual movement dates and activity is logged', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertRedirect();

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeTrue();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();
    expect($setting?->allow_future_actual_movement_dates)->toBeTrue();

    $log = Activity::query()
        ->where('subject_type', CrewOperationsSetting::class)
        ->where('subject_id', $setting->id)
        ->where('description', 'updated crew operations future actual movement dates setting')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['company_id'])->toBe($company->id)
        ->and($log->properties['setting_key'])->toBe(CrewOperationsSettings::CONFIG_ALLOW_FUTURE_ACTUAL_MOVEMENT_DATES)
        ->and($log->properties['old']['allow_future_actual_movement_dates'])->toBeFalse()
        ->and($log->properties['attributes']['allow_future_actual_movement_dates'])->toBeTrue();
});

test('unauthorized users cannot change allow future actual movement dates', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertForbidden();

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse();
});

test('allow future actual movement dates is company scoped', function () {
    ['user' => $userA, 'company' => $companyA] = makeFutureMovementSettingsFixtures();
    ['company' => $companyB] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($userA, $companyA, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    $this->actingAs($userA)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertRedirect();

    expect(CrewOperationsSettings::allowFutureActualMovementDates($companyA->id))->toBeTrue()
        ->and(CrewOperationsSettings::allowFutureActualMovementDates($companyB->id))->toBeFalse();
});

test('saving unrelated settings preserves allow future actual movement dates', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]);

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeTrue();

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'max_home_days' => 45,
            // omit allow_future_actual_movement_dates to prove preservation through sometimes validation
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertRedirect();

    // Explicit true still preserved after unrelated max_home_days change
    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeTrue()
        ->and(CrewOperationsSettings::maxHomeDays($company->id))->toBe(45);

    // Save without the key via Support helper path
    CrewOperationsSettings::saveSettings($company->id, [], 50, true, [
        'actor_id' => $user->id,
    ]);

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeTrue()
        ->and(CrewOperationsSettings::maxHomeDays($company->id))->toBe(50);
});

test('settings index exposes allow future actual movement dates', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/settings')
            ->where('crew_settings.allow_future_actual_movement_dates', true)
        );
});
