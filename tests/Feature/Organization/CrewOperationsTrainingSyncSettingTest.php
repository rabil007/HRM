<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewOperationsSetting;
use App\Models\Currency;
use App\Models\User;
use App\Support\CrewOperations\CrewOperationsSettings;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function makeCrewTrainingSettingsFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CTS',
        'name' => 'Crew Training Settings Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CTS',
        'name' => 'Crew Training Settings Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Training Settings Co',
        'slug' => 'crew-training-settings-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

test('training sync setting defaults to disabled', function () {
    ['company' => $company] = makeCrewTrainingSettingsFixtures();

    expect(CrewOperationsSettings::syncTrainingToEmployeeTrainingEnabled($company->id))->toBeFalse();
});

test('authorized users can view sync_training_to_employee_training in settings', function () {
    ['user' => $user, 'company' => $company] = makeCrewTrainingSettingsFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.settings.view']);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'sync_training_to_employee_training' => true,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/settings')
            ->where('crew_settings.sync_training_to_employee_training', true)
        );
});

test('authorized users can update sync_training_to_employee_training setting', function () {
    ['user' => $user, 'company' => $company] = makeCrewTrainingSettingsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
            'max_home_days' => 30,
            'sync_sea_service' => true,
            'sync_training_to_employee_training' => true,
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
        ])
        ->assertRedirect();

    expect(CrewOperationsSettings::syncTrainingToEmployeeTrainingEnabled($company->id))->toBeTrue();

    $setting = CrewOperationsSetting::query()->where('company_id', $company->id)->first();
    expect($setting?->sync_training_to_employee_training)->toBeTrue();

    $log = Activity::query()
        ->where('subject_type', CrewOperationsSetting::class)
        ->where('subject_id', $setting->id)
        ->where('description', 'updated crew operations training sync setting')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['attributes']['sync_training_to_employee_training'])->toBeTrue()
        ->and($log->properties['old']['sync_training_to_employee_training'])->toBeFalse();
});
