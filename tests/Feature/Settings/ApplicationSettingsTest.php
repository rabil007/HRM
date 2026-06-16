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
        ->post(route('application.general.update'), [
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

test('salary certificate signature and stamp can be uploaded via general settings', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $payload = [
        'app_name' => 'Herd OMS',
        'company_name' => 'Herd OMS LLC',
        'support_email' => 'hr@example.com',
        'support_phone' => '',
        'company_address' => '',
        'timezone' => 'Asia/Dubai',
        'currency' => 'USD',
        'date_format' => 'Y-m-d',
        'salary_certificate_signature' => UploadedFile::fake()->image('signature.png', 400, 120),
        'salary_certificate_stamp' => UploadedFile::fake()->image('stamp.png', 500, 200),
    ];

    $this->actingAs($user)
        ->post(route('application.general.update'), $payload)
        ->assertRedirect()
        ->assertSessionHas('success');

    $signaturePath = setting(SettingKey::SalaryCertificateSignature);
    $stampPath = setting(SettingKey::SalaryCertificateStamp);

    expect($signaturePath)->not->toBeNull()
        ->and($stampPath)->not->toBeNull();

    Storage::disk('public')->assertExists((string) $signaturePath);
    Storage::disk('public')->assertExists((string) $stampPath);

    $this->actingAs($user)
        ->delete(route('application.branding.remove', ['asset' => SettingKey::SalaryCertificateSignature]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(setting(SettingKey::SalaryCertificateSignature))->toBeNull();
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

test('inertia shared name and app-name meta use configured application name', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::AppName],
        ['value' => 'Herd OMS', 'type' => 'string'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Herd OMS')
            ->where('settings.app_name', 'Herd OMS'),
        )
        ->assertSee('meta name="app-name" content="Herd OMS"', false);
});
