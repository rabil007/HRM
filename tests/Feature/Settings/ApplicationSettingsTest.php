<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Settings\SettingKey;
use Database\Seeders\AppSettingsSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('user with platform view access can open application settings in view mode', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, []);

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

test('tenant user without platform access cannot access global platform settings props', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

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

test('user with platform manage access can update general settings', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

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

test('tenant user without platform manage access receives 403 on every global settings mutation endpoint', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
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

test('smtp and branding updates require platform manage access', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

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

test('branding logo can be uploaded and removed by platform manage user', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

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
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

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
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'Herd OMS')
            ->where('settings.platform.app_name', 'Herd OMS')
            ->where('settings.app_name', 'Herd OMS'),
        );
});

test('tenant switching does not grant platform authority', function () {
    $tenantOwner = User::factory()->create();
    $companyA = setupCompanyWithSettingsPermissions($tenantOwner, ['settings.application.view', 'settings.application.update']);

    $otherUser = User::factory()->create();
    $companyB = setupCompanyWithSettingsPermissions($otherUser, ['settings.application.view', 'settings.application.update']);
    grantCompanyPermissions($tenantOwner, $companyB, ['settings.application.view', 'settings.application.update']);

    $this->actingAs($tenantOwner)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('application.edit'))
        ->assertForbidden();

    $this->actingAs($tenantOwner)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('application.edit'))
        ->assertForbidden();
});

test('decrypted smtp password is never exposed in application settings props', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::MailPassword],
        ['value' => Crypt::encryptString('super-secret-smtp-password'), 'type' => 'encrypted'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('smtp.has_password', true)
            ->where('smtp.password', ''),
        );
});
