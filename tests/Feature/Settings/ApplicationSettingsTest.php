<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Settings\SettingKey;
use Database\Seeders\AppSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('application settings page is displayed', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'platform.settings.view',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/application')
            ->has('general')
            ->has('branding')
            ->has('preferences')
            ->has('esign_placement')
            ->has('general.app_name')
            ->has('general.support_email')
            ->has('general.timezone')
            ->has('general.date_format'),
        );
});

test('general settings can be updated and are cached', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
        'platform.settings.view',
        'platform.settings.update',
    ]);

    $this->actingAs($user)
        ->post(route('application.general.update'), [
            'app_name' => 'Herd OMS',
            'support_email' => 'support@herd.test',
            'support_phone' => '+971500000000',
            'timezone' => 'Asia/Dubai',
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
        'platform.settings.update',
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

test('salary certificate assets are no longer removable via platform branding route', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.update',
        'platform.settings.update',
    ]);

    $this->actingAs($user)
        ->delete(route('application.branding.remove', ['asset' => SettingKey::SalaryCertificateSignature]))
        ->assertNotFound();
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

test('inertia shared name and app-name meta use configured application name', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::AppName],
        ['value' => 'Herd OMS', 'type' => 'string'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'platform.settings.view',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Herd OMS')
            ->where('settings.platform.app_name', 'Herd OMS')
            ->where('settings.app_name', 'Herd OMS'),
        );
});
