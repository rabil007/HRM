<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Settings\SettingKey;
use Database\Seeders\AppSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function setupCompanyWithApplicationSettingsPermissions(User $user, array $permissions): void
{
    $country = Country::query()->create([
        'code' => 'APP',
        'name' => 'AppLand',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-app-settings',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, $permissions);
}

test('application settings page is displayed', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/application')
            ->has('general')
            ->has('branding')
            ->has('preferences'),
        );
});

test('general settings can be updated and are cached', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->put(route('application.general.update'), [
            'app_name' => 'Herd OMS',
            'company_name' => 'Herd OMS LLC',
            'support_email' => 'support@herd.test',
            'support_phone' => '+971500000000',
            'company_address' => 'Dubai, UAE',
            'timezone' => 'Asia/Dubai',
            'currency' => 'USD',
            'date_format' => 'd/m/Y',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(setting(SettingKey::AppName))->toBe('Herd OMS');
    expect(app_name())->toBe('Herd OMS');

    Cache::forget('app.settings.all');
    expect(setting(SettingKey::AppName))->toBe('Herd OMS');
});

test('branding logo can be uploaded and removed', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->post(route('application.branding.update'), [
            'main_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $path = setting(SettingKey::MainLogo);
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists((string) $path);

    $this->actingAs($user)
        ->delete(route('application.branding.remove', ['asset' => SettingKey::MainLogo]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(setting(SettingKey::MainLogo))->toBeNull();
});

test('users without permission cannot access application settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertForbidden();
});

test('app settings seeder creates defaults', function () {
    AppSetting::query()->delete();
    Cache::forget('app.settings.all');

    $this->seed(AppSettingsSeeder::class);

    expect(AppSetting::query()->where('key', SettingKey::AppName)->value('value'))
        ->toBe(config('app.name', 'Laravel'));
});
