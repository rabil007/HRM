<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewOperationsSetting;
use App\Models\Currency;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsSettings;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

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

test('allow future actual movement dates is always disabled at runtime', function () {
    ['company' => $company] = makeFutureMovementSettingsFixtures();

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse()
        ->and(CrewOperationsSettings::futureActualOverrideEnvironmentsAllowed())->toBeFalse()
        ->and(CrewOperationsSettings::CONFIG_ALLOW_FUTURE_ACTUAL_MOVEMENT_DATES)
        ->toBe('crew_operations.allow_future_actual_movement_dates');

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => true,
    ]);
    CrewOperationsSettings::clearCache($company->id);

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse();
});

test('authorized users cannot enable the retired future actual override', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertSessionHasErrors('allow_future_actual_movement_dates');

    expect(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse();

    expect(fn () => CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]))->toThrow(ValidationException::class);
});

test('unauthorized users cannot change crew operations settings', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'allow_future_actual_movement_dates' => true,
        ]))
        ->assertForbidden();
});

test('saving settings clears any leftover stored override flag', function () {
    ['user' => $user, 'company' => $company] = makeFutureMovementSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => true,
        'max_home_days' => 30,
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), futureMovementSettingsPayload([
            'max_home_days' => 45,
            'allow_future_actual_movement_dates' => false,
        ]))
        ->assertRedirect();

    expect(CrewOperationsSetting::query()->where('company_id', $company->id)->value('allow_future_actual_movement_dates'))
        ->toBeFalse()
        ->and(CrewOperationsSettings::maxHomeDays($company->id))->toBe(45)
        ->and(CrewOperationsSettings::allowFutureActualMovementDates($company->id))->toBeFalse();
});

test('settings index always exposes the override as disabled', function () {
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
            ->where('crew_settings.allow_future_actual_movement_dates', false)
        );
});
