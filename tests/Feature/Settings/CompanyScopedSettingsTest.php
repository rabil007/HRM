<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Settings\CompanyCurrency;
use App\Support\Settings\CompanyTimezone;
use App\Support\Settings\SettingKey;
use App\Support\Settings\SharedSettingsPresenter;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('platform admin can update platform general settings without company fields', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
        'platform.settings.view',
        'platform.settings.update',
    ]);

    $this->actingAs($user)
        ->post(route('application.general.update'), [
            'app_name' => 'OMS Platform',
            'support_email' => 'platform@example.test',
            'support_phone' => '+971500000001',
            'timezone' => 'Asia/Dubai',
            'date_format' => 'd/m/Y',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(setting(SettingKey::AppName))->toBe('OMS Platform')
        ->and(setting(SettingKey::SupportEmail))->toBe('platform@example.test')
        ->and(setting(SettingKey::Timezone))->toBe('Asia/Dubai');

    expect(Activity::query()->where('description', 'updated platform general settings')->exists())->toBeTrue();
});

test('whatsapp-only users do not receive smtp or platform configuration props', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/application')
            ->where('general', null)
            ->where('branding', null)
            ->where('smtp', null)
            ->where('preferences', null)
            ->where('esign_placement', null)
            ->where('can.platform_view', false)
            ->where('can.whatsapp_view', true)
            ->has('whatsapp'),
        );
});

test('company admin without platform permission cannot update global settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'companies.view',
        'companies.update',
        'company.settings.update',
    ]);

    $this->actingAs($user)
        ->post(route('application.general.update'), [
            'app_name' => 'Hijack',
            'support_email' => 'x@y.test',
            'support_phone' => '',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
        ])
        ->assertForbidden();
});

test('shared inertia settings expose platform and company scopes separately', function () {
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'SCI',
        'name' => 'Scoped Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Scoped Co',
        'slug' => 'scoped-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'email' => 'hr@scoped.test',
        'status' => 'active',
    ]);

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::AppName],
        ['value' => 'Platform OMS', 'type' => 'string'],
    );

    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.companies.show', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.platform.app_name', 'Platform OMS')
            ->where('settings.company.id', $company->id)
            ->where('settings.company.name', 'Scoped Co')
            ->where('settings.company.currency.code', 'AED')
            ->where('settings.company.timezone', 'Asia/Dubai'),
        );
});

test('company currency resolver prefers company currency over legacy global', function () {
    $country = Country::query()->create([
        'code' => 'CCX',
        'name' => 'Currency Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'EUR'],
        [
            'name' => 'Euro',
            'symbol' => '€',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Euro Co',
        'slug' => 'euro-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Europe/Paris',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::Currency],
        ['value' => 'USD', 'type' => 'string'],
    );

    expect(CompanyCurrency::codeForCompany($company))->toBe('EUR');
});

test('company timezone resolver validates and falls back safely', function () {
    $country = Country::query()->create([
        'code' => 'TZX',
        'name' => 'TZ Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'TZ Co',
        'slug' => 'tz-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Not/ARealZone',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::Timezone],
        ['value' => 'Asia/Dubai', 'type' => 'string'],
    );

    expect(CompanyTimezone::forCompany($company))->toBe('Asia/Dubai');

    $company->update(['timezone' => 'America/New_York']);
    expect(CompanyTimezone::forCompany($company->fresh()))->toBe('America/New_York');
});

test('single company migration copies only missing identity fields', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyName],
        ['value' => 'Legacy Name', 'type' => 'string'],
    );
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyAddress],
        ['value' => 'Legacy Address', 'type' => 'string'],
    );

    $country = Country::query()->create([
        'code' => 'MIG',
        'name' => 'Mig Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );

    $company = Company::query()->create([
        'name' => 'Existing Name',
        'slug' => 'existing-name',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'address' => null,
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->artisan('settings:migrate-legacy-company-settings')->assertSuccessful();

    $company->refresh();

    expect($company->name)->toBe('Existing Name')
        ->and($company->address)->toBe('Legacy Address');
});

test('multi company installation does not copy legacy identity to all companies', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyName],
        ['value' => 'Legacy Global', 'type' => 'string'],
    );

    $country = Country::query()->create([
        'code' => 'MUL',
        'name' => 'Multi Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );

    Company::query()->create([
        'name' => 'Co One',
        'slug' => 'co-one',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    Company::query()->create([
        'name' => 'Co Two',
        'slug' => 'co-two',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->artisan('settings:migrate-legacy-company-settings')
        ->expectsOutputToContain('Multiple companies')
        ->assertSuccessful();

    expect(Company::query()->where('name', 'Legacy Global')->exists())->toBeFalse();
});

test('shared settings presenter returns null company without active company', function () {
    $presenter = app(SharedSettingsPresenter::class);
    $payload = $presenter->forInertia(null);

    expect($payload['company'])->toBeNull()
        ->and($payload['platform']['app_name'])->not->toBeEmpty();
});
