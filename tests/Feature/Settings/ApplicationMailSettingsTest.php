<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

test('application settings page includes smtp configuration', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('smtp')
            ->where('smtp.host', fn ($host) => is_string($host))
        );
});

test('smtp settings can be saved with encrypted password', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->put(route('application.smtp.update'), [
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

    Cache::forget('app.settings.all');

    expect(setting(SettingKey::MailHost))->toBe('smtp.hostinger.com')
        ->and(setting(SettingKey::MailPort))->toBe('465')
        ->and(Crypt::decryptString((string) setting(SettingKey::MailPassword)))->toBe('secret-pass');
});

test('smtp password is kept when update omits password', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::MailPassword],
        ['value' => Crypt::encryptString('keep-me'), 'type' => 'encrypted'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->put(route('application.smtp.update'), [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'username' => 'hr@overseas-ms.com',
            'password' => '',
            'encryption' => 'ssl',
            'from_address' => 'hr@overseas-ms.com',
            'from_name' => 'Herd OMS',
        ])
        ->assertRedirect();

    Cache::forget('app.settings.all');

    expect(Crypt::decryptString((string) setting(SettingKey::MailPassword)))->toBe('keep-me');
});

test('test email can be sent from smtp settings', function () {
    $this->mock(MailSettingsService::class, function ($mock): void {
        $mock->shouldReceive('sendTestEmail')
            ->once()
            ->with('test@example.com', Mockery::type('array'));
    });

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->postJson(route('application.smtp.test'), [
            'recipient' => 'test@example.com',
            'host' => 'smtp.hostinger.com',
            'port' => 587,
            'username' => 'user',
            'password' => 'pass',
            'encryption' => 'tls',
            'from_address' => 'hr@example.com',
            'from_name' => 'HRM Test',
        ])
        ->assertOk()
        ->assertJson(['message' => 'Test email sent to test@example.com.']);
});

test('users without permission cannot update smtp settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->put(route('application.smtp.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'a@b.com',
            'from_name' => 'Test',
        ])
        ->assertForbidden();
});
