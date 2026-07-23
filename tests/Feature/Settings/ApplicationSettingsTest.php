<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Settings\SettingKey;
use Database\Seeders\AppSettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('user with settings.application.view can open application settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
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
            ->has('smtp')
            ->where('can.platform_view', true)
            ->where('can.platform_update', false)
            ->has('general.app_name')
            ->has('general.support_email')
            ->has('general.timezone')
            ->has('general.date_format'),
        );
});

test('user without settings.application.view cannot access global platform settings props', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertForbidden();
});

test('whatsapp-only users can access only whatsapp settings on the application page', function () {
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

test('user with settings.application.update can update general settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
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

test('user without settings.application.update receives 403 on every global settings mutation endpoint', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
    ]);

    $this->actingAs($user)
        ->post(route('application.general.update'), [
            'app_name' => 'Blocked',
            'support_email' => 'blocked@example.test',
            'support_phone' => '',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('application.branding.update'), [
            'main_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('application.branding.remove', ['asset' => SettingKey::MainLogo]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('application.smtp.update'), [
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'mailer',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_address' => 'noreply@example.test',
            'from_name' => 'OMS',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson(route('application.smtp.test'), [
            'recipient' => 'test@example.test',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('application.esign-placement.update', ['documentType' => 'salary_declaration']), [
            'signature' => [
                'page' => 1,
                'x' => 10,
                'y' => 10,
                'width' => 100,
                'height' => 40,
            ],
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('application.esign-placement.destroy', ['documentType' => 'salary_declaration']))
        ->assertForbidden();
});

test('smtp and branding updates require settings.application.update', function () {
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
        ->post(route('application.smtp.update'), [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'username' => 'hr@overseas-ms.com',
            'password' => 'secret-pass',
            'encryption' => 'ssl',
            'from_address' => 'hr@overseas-ms.com',
            'from_name' => 'Herd OMS',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
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

test('salary certificate assets are no longer removable via platform branding route', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->delete(route('application.branding.remove', ['asset' => SettingKey::SalaryCertificateSignature]))
        ->assertNotFound();
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
