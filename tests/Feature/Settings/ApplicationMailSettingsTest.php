<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Settings\MailSettingsService;
use App\Support\Settings\SettingKey;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

test('application settings page includes smtp configuration', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('smtp')
            ->has('smtp.email_footer')
            ->where('smtp.host', fn ($host) => is_string($host))
            ->where('smtp.email_footer.tagline', fn ($value) => is_string($value)),
        );
});

test('application settings page masks the smtp password', function () {
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::MailPassword],
        ['value' => Crypt::encryptString('secret-pass'), 'type' => 'encrypted'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smtp.password', '')
            ->where('smtp.has_password', true),
        );
});

test('outgoing email views render the branding footer and inline logo', function () {
    Storage::fake('public');

    $logoPath = 'settings/email_branding_logo-test.png';
    Storage::disk('public')->put(
        $logoPath,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    );

    foreach ([
        [SettingKey::CompanyName, 'Overseas Marine Services Sole Proprietorship LLC'],
        [SettingKey::MailFooterTagline, 'Your Complete Marine Solutions'],
        [SettingKey::MailFooterWebsite, 'www.overseas-ms.com'],
        [SettingKey::MailFooterCertifications, 'ISO 9001:2015 | ISO 14001:2015 | ISO 45001:2018 | ICV Certified'],
        [SettingKey::SupportEmail, 'hr@overseas-ms.com'],
        [SettingKey::CompanyAddress, 'Office 402, Centro Capital Centre, Abu Dhabi, U.A.E'],
        [SettingKey::EmailBrandingLogo, $logoPath],
    ] as [$key, $value]) {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $key === SettingKey::EmailBrandingLogo ? 'file' : 'string'],
        );
    }

    Cache::forget('app.settings.all');

    $html = view('mail.smtp-test', ['subject' => 'Test', 'body' => 'Hello'])->render();

    expect($html)
        ->toContain('Overseas Marine Services Sole Proprietorship LLC')
        ->toContain('Your Complete Marine Solutions')
        ->toContain('www.overseas-ms.com')
        ->toContain('ISO 9001:2015 | ISO 14001:2015 | ISO 45001:2018 | ICV Certified')
        ->toContain('hr@overseas-ms.com')
        ->toContain('data:image/');

    $htmlBody = null;

    Event::listen(MessageSending::class, function (MessageSending $event) use (&$htmlBody): void {
        $htmlBody = $event->message->getHtmlBody();
    });

    config(['mail.default' => 'array']);

    Mail::send('mail.smtp-test', ['subject' => 'Logo test', 'body' => 'Checking inline logo.'], function ($message): void {
        $message->to('recipient@example.com')->subject('Logo test');
    });

    expect($htmlBody)->not->toBeNull()
        ->and($htmlBody)->toContain('data:image/')
        ->and($htmlBody)->not->toContain('cid:');
});

test('smtp settings can be saved with encrypted password', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

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
        ->post(route('application.smtp.update'), [
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
            ->with('test@example.com', Mockery::type('array'), '', '', null);
    });

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('application.smtp.test'), [
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

test('test email accepts custom subject and body', function () {
    $this->mock(MailSettingsService::class, function ($mock): void {
        $mock->shouldReceive('sendTestEmail')
            ->once()
            ->with(
                'test@example.com',
                Mockery::type('array'),
                'Custom subject',
                'Custom body text',
                null,
            );
    });

    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('application.smtp.test'), [
            'recipient' => 'test@example.com',
            'host' => 'smtp.hostinger.com',
            'port' => 587,
            'username' => 'user',
            'encryption' => 'tls',
            'from_address' => 'hr@example.com',
            'from_name' => 'HRM Test',
            'subject' => 'Custom subject',
            'body' => 'Custom body text',
        ])
        ->assertOk();
});

test('smtp settings can save email footer text', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->post(route('application.smtp.update'), [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'username' => 'hr@overseas-ms.com',
            'encryption' => 'ssl',
            'from_address' => 'hr@overseas-ms.com',
            'from_name' => 'Herd OMS',
            'mail_footer_tagline' => 'Marine solutions',
            'mail_footer_website' => 'www.example.test',
            'mail_footer_certifications' => 'ISO 9001:2015',
        ])
        ->assertRedirect();

    Cache::forget('app.settings.all');

    expect(setting(SettingKey::MailFooterTagline))->toBe('Marine solutions')
        ->and(setting(SettingKey::MailFooterWebsite))->toBe('www.example.test')
        ->and(setting(SettingKey::MailFooterCertifications))->toBe('ISO 9001:2015');
});

test('users without permission cannot update smtp settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->post(route('application.smtp.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'a@b.com',
            'from_name' => 'Test',
        ])
        ->assertForbidden();
});
