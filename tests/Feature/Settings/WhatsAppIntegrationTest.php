<?php

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

test('owner can view whatsapp integration settings page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.whatsapp.view']);

    $this->actingAs($user)
        ->get(route('integrations.whatsapp.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/integrations/whatsapp')
            ->has('settings')
            ->has('callback_url')
            ->where('settings.has_access_token', false),
        );
});

test('users without permission cannot view whatsapp integration settings', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('integrations.whatsapp.edit'))
        ->assertForbidden();
});

test('whatsapp settings can be saved with encrypted secrets', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    $this->actingAs($user)
        ->put(route('integrations.whatsapp.update'), [
            'business_account_id' => '123456789',
            'phone_number_id' => '987654321',
            'access_token' => 'test-access-token',
            'app_id' => 'app-id-123',
            'app_secret' => 'test-app-secret',
            'webhook_verify_token' => 'verify-token-abc',
            'enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = WhatsAppSetting::current();

    expect($settings->business_account_id)->toBe('123456789')
        ->and($settings->phone_number_id)->toBe('987654321')
        ->and($settings->access_token)->toBe('test-access-token')
        ->and($settings->app_secret)->toBe('test-app-secret')
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->isConfigured())->toBeTrue();

    $raw = WhatsAppSetting::query()->find(1);

    expect($raw?->getRawOriginal('access_token'))->not->toBe('test-access-token')
        ->and($raw?->getRawOriginal('app_secret'))->not->toBe('test-app-secret');
});

test('whatsapp secrets are kept when update omits them', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'keep-access-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'keep-app-secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $this->actingAs($user)
        ->put(route('integrations.whatsapp.update'), [
            'business_account_id' => '123456789',
            'phone_number_id' => '987654321',
            'access_token' => '',
            'app_id' => 'app-id-123',
            'app_secret' => '',
            'webhook_verify_token' => 'verify-token-abc',
            'enabled' => true,
        ])
        ->assertRedirect();

    $settings = WhatsAppSetting::current();

    expect($settings->access_token)->toBe('keep-access-token')
        ->and($settings->app_secret)->toBe('keep-app-secret');
});

test('whatsapp test connection returns success when meta api responds ok', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'verified_name' => 'OMS HRM',
            'display_phone_number' => '+971500000000',
            'id' => '987654321',
        ], 200),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    $this->actingAs($user)
        ->postJson(route('integrations.whatsapp.test'), [
            'business_account_id' => '123456789',
            'phone_number_id' => '987654321',
            'access_token' => 'valid-token',
            'app_id' => 'app-id-123',
            'webhook_verify_token' => 'verify-token-abc',
            'enabled' => true,
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Connection successful.',
        ]);
});

test('whatsapp test connection returns meta error message on failure', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    $this->actingAs($user)
        ->postJson(route('integrations.whatsapp.test'), [
            'business_account_id' => '123456789',
            'phone_number_id' => '987654321',
            'access_token' => 'invalid-token',
            'app_id' => 'app-id-123',
            'webhook_verify_token' => 'verify-token-abc',
            'enabled' => true,
        ])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid OAuth access token.',
        ]);
});

test('whatsapp webhook verify returns challenge when token matches', function () {
    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'my-verify-token',
        'enabled' => true,
    ]);

    $this->get(route('whatsapp.webhook', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'my-verify-token',
        'hub_challenge' => 'challenge-string-123',
    ]))
        ->assertOk()
        ->assertSee('challenge-string-123');
});

test('whatsapp webhook verify supports meta dotted query parameters', function () {
    WhatsAppSetting::current()->update([
        'webhook_verify_token' => 'HERD_OMS_WHATSAPP_VERIFY_TOKEN',
    ]);

    $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=HERD_OMS_WHATSAPP_VERIFY_TOKEN&hub.challenge=123456')
        ->assertOk()
        ->assertSee('123456');
});

test('whatsapp webhook legacy route remains available', function () {
    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'my-verify-token',
        'enabled' => true,
    ]);

    $this->get(route('webhooks.whatsapp', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'my-verify-token',
        'hub_challenge' => 'challenge-string-123',
    ]))
        ->assertOk()
        ->assertSee('challenge-string-123');
});

test('whatsapp webhook verify rejects invalid token', function () {
    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'my-verify-token',
        'enabled' => true,
    ]);

    $this->get(route('webhooks.whatsapp', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong-token',
        'hub_challenge' => 'challenge-string-123',
    ]))
        ->assertForbidden();
});

test('users without permission cannot update whatsapp settings', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.whatsapp.view']);

    $this->actingAs($user)
        ->put(route('integrations.whatsapp.update'), [
            'business_account_id' => '123456789',
            'phone_number_id' => '987654321',
            'access_token' => 'test-access-token',
            'app_id' => 'app-id-123',
            'app_secret' => 'test-app-secret',
            'webhook_verify_token' => 'verify-token-abc',
            'enabled' => true,
        ])
        ->assertForbidden();
});

test('whatsapp test text message sends successfully using stored credentials', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '971501234567', 'wa_id' => '971501234567']],
            'messages' => [['id' => 'wamid.test-text-id']],
        ], 200),
    ]);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    $this->actingAs($user)
        ->postJson(route('integrations.whatsapp.send-test-text'), [
            'phone' => '+971501234567',
            'message' => 'Hello from the test panel.',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message_id' => 'wamid.test-text-id',
        ]);
});

test('whatsapp test document upload sends file successfully', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'media-test-123'], 200),
        'graph.facebook.com/*/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.test-doc-id']],
        ], 200),
    ]);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.view',
        'settings.integrations.whatsapp.update',
    ]);

    $this->actingAs($user)
        ->post(route('integrations.whatsapp.send-test-document'), [
            'phone' => '+971501234567',
            'caption' => 'Test PDF from settings',
            'file' => UploadedFile::fake()->createWithContent('sample.pdf', '%PDF-1.4 test content'),
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message_id' => 'wamid.test-doc-id',
            'media_id' => 'media-test-123',
        ]);
});
