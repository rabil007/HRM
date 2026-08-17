<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

function configuredMetaWhatsAppWebhook(): WhatsAppSetting
{
    $settings = WhatsAppSetting::current();
    $settings->storeFromValidated([
        'business_account_id' => 'meta-business-account-123',
        'phone_number_id' => 'meta-phone-number-456',
        'access_token' => 'meta-access-token',
        'app_id' => 'meta-app-789',
        'app_secret' => 'meta-app-secret',
        'webhook_verify_token' => 'meta-verify-token',
        'enabled' => true,
    ]);

    return $settings->fresh();
}

/**
 * @return array{announcement: Announcement, delivery: AnnouncementDelivery}
 */
function makeMetaWhatsAppWebhookDelivery(Company $company, string $messageId): array
{
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Signed webhook announcement',
        'body_html' => '<p>Webhook body</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => [AnnouncementChannel::WhatsApp->value],
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'phone' => '971500000001',
        'public_token' => Str::random(48),
    ]);

    $delivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::WhatsApp,
        'status' => AnnouncementDeliveryStatus::Sent,
        'provider_reference' => $messageId,
        'queued_at' => now(),
        'sent_at' => now(),
    ]);

    return compact('announcement', 'delivery');
}

/**
 * @return array<string, mixed>
 */
function metaWhatsAppStatusPayload(string $messageId, string $status = 'delivered'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'meta-business-account-123',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '+971500000000',
                                'phone_number_id' => 'meta-phone-number-456',
                            ],
                            'statuses' => [
                                [
                                    'id' => $messageId,
                                    'status' => $status,
                                    'timestamp' => '1770000000',
                                    'recipient_id' => '971500000001',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function metaWhatsAppWebhookBody(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function metaWhatsAppWebhookSignature(string $body, string $secret = 'meta-app-secret'): string
{
    return 'sha256='.hash_hmac('sha256', $body, $secret);
}

function metaWhatsAppWebhookRateLimitKey(string $ip = '127.0.0.1'): string
{
    return 'whatsapp-webhook:'.hash('sha256', $ip);
}

function postMetaWhatsAppWebhook(
    TestCase $testCase,
    string $body,
    ?string $signature,
    string $routeName = 'whatsapp.webhook',
): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($signature !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    }

    return $testCase->call('POST', route($routeName), [], [], [], $server, $body);
}

test('valid meta signature authenticates the raw body and updates the matching delivery', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.valid-signature');
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.valid-signature'));

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Delivered)
        ->delivered_at->not->toBeNull();
});

test('invalid meta signature is rejected', function () {
    configuredMetaWhatsAppWebhook();
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.invalid-signature'));

    postMetaWhatsAppWebhook($this, $body, 'sha256='.str_repeat('0', 64))
        ->assertForbidden();
});

test('invalid webhook is stateless and does not write framework persistence', function () {
    configuredMetaWhatsAppWebhook();
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');
    app('session')->forgetDrivers();
    Cache::forgetDriver('database');
    $sessionCount = DB::table('sessions')->count();
    $cacheCount = DB::table('cache')->count();
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.framework-persistence'));

    postMetaWhatsAppWebhook($this, $body, 'sha256='.str_repeat('0', 64))
        ->assertForbidden()
        ->assertCookieMissing(config('session.cookie'));

    expect(DB::table('sessions')->count())->toBe($sessionCount)
        ->and(DB::table('cache')->count())->toBe($cacheCount);
});

test('missing meta signature is rejected', function () {
    configuredMetaWhatsAppWebhook();
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.missing-signature'));

    postMetaWhatsAppWebhook($this, $body, null)
        ->assertForbidden();
});

test('rejected webhook does not restore a deleted integration setting', function () {
    $settings = configuredMetaWhatsAppWebhook();
    $settings->delete();
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.deleted-integration'));

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertForbidden();

    expect(WhatsAppSetting::query()->find($settings->id))->toBeNull()
        ->and(WhatsAppSetting::withTrashed()->find($settings->id)?->trashed())->toBeTrue();
});

test('signature for an unmodified body does not authenticate a modified body', function () {
    configuredMetaWhatsAppWebhook();
    $payload = metaWhatsAppStatusPayload('wamid.modified-body');
    $originalBody = metaWhatsAppWebhookBody($payload);
    $modifiedBody = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
    );

    postMetaWhatsAppWebhook($this, $modifiedBody, metaWhatsAppWebhookSignature($originalBody))
        ->assertForbidden();
});

test('rejected webhook performs zero announcement delivery mutation', function () {
    $settings = configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['announcement' => $announcement, 'delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery(
        $company,
        'wamid.zero-mutation',
    );
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.zero-mutation', 'failed'));
    $deliveryUpdatedAt = $delivery->updated_at;
    $announcementUpdatedAt = $announcement->updated_at;
    $settingsUpdatedAt = $settings->updated_at;

    postMetaWhatsAppWebhook($this, $body, 'sha256='.str_repeat('f', 64))
        ->assertForbidden();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->failed_at->toBeNull()
        ->failure_reason->toBeNull()
        ->updated_at->toEqual($deliveryUpdatedAt)
        ->and($announcement->fresh())
        ->status->toBe(AnnouncementStatus::Published)
        ->updated_at->toEqual($announcementUpdatedAt)
        ->and($settings->fresh()->updated_at)->toEqual($settingsUpdatedAt)
        ->and(AnnouncementDelivery::query()->count())->toBe(1)
        ->and(RateLimiter::attempts(metaWhatsAppWebhookRateLimitKey()))->toBe(0);
});

test('authenticated webhook is throttled before delivery processing', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.throttled');
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.throttled'));
    RateLimiter::increment(metaWhatsAppWebhookRateLimitKey(), 60, 120);

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('existing meta get verification still returns the challenge', function () {
    configuredMetaWhatsAppWebhook();

    $this->get(route('whatsapp.webhook', [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'meta-verify-token',
        'hub_challenge' => 'existing-get-challenge',
    ]))
        ->assertOk()
        ->assertSeeText('existing-get-challenge');
});

test('valid signature cannot mutate delivery for a different business account', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.other-integration');
    $payload = metaWhatsAppStatusPayload('wamid.other-integration');
    $payload['entry'][0]['id'] = 'another-business-account';
    $body = metaWhatsAppWebhookBody($payload);

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('valid signature cannot mutate delivery for a different phone number', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.other-phone-number');
    $payload = metaWhatsAppStatusPayload('wamid.other-phone-number');
    $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'another-phone-number';
    $body = metaWhatsAppWebhookBody($payload);

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('valid signature cannot mutate delivery for a non whatsapp object', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.non-whatsapp-object');
    $payload = metaWhatsAppStatusPayload('wamid.non-whatsapp-object');
    $payload['object'] = 'page';
    $body = metaWhatsAppWebhookBody($payload);

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('valid signature cannot mutate delivery for a non whatsapp messaging product', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.non-whatsapp-product');
    $payload = metaWhatsAppStatusPayload('wamid.non-whatsapp-product');
    $payload['entry'][0]['changes'][0]['value']['messaging_product'] = 'messenger';
    $body = metaWhatsAppWebhookBody($payload);

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('valid signature cannot mutate a delivery with an inconsistent tenant ownership chain', function () {
    configuredMetaWhatsAppWebhook();
    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = Company::query()->create([
        'name' => 'Ownership Chain Company',
        'slug' => 'ownership-chain-company',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $companyA->country_id,
        'currency_id' => $companyA->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($companyA, 'wamid.ownership-chain');
    $delivery->update(['company_id' => $companyB->id]);
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.ownership-chain'));

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});

test('replayed status callback is idempotent', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.replayed');
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.replayed'));
    $signature = metaWhatsAppWebhookSignature($body);

    postMetaWhatsAppWebhook($this, $body, $signature)
        ->assertNoContent();

    $deliveredAt = $delivery->fresh()->delivered_at;
    $updatedAt = $delivery->fresh()->updated_at;
    $this->travel(1)->minute();

    postMetaWhatsAppWebhook($this, $body, $signature)
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Delivered)
        ->delivered_at->toEqual($deliveredAt)
        ->updated_at->toEqual($updatedAt);
});

test('replayed older status cannot regress delivery progress', function () {
    configuredMetaWhatsAppWebhook();
    $company = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    ['delivery' => $delivery] = makeMetaWhatsAppWebhookDelivery($company, 'wamid.replayed-progress');
    $readBody = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.replayed-progress', 'read'));

    postMetaWhatsAppWebhook($this, $readBody, metaWhatsAppWebhookSignature($readBody))
        ->assertNoContent();

    $readAt = $delivery->fresh()->read_at;
    $sentBody = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.replayed-progress', 'sent'));
    $this->travel(1)->minute();

    postMetaWhatsAppWebhook($this, $sentBody, metaWhatsAppWebhookSignature($sentBody))
        ->assertNoContent();

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Read)
        ->read_at->toEqual($readAt);
});

test('ambiguous provider reference across companies does not mutate either tenant', function () {
    configuredMetaWhatsAppWebhook();
    $companyA = setupCompanyWithSettingsPermissions(User::factory()->create(), []);
    $companyB = Company::query()->create([
        'name' => 'Second Webhook Company',
        'slug' => 'second-webhook-company',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $companyA->country_id,
        'currency_id' => $companyA->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    ['delivery' => $deliveryA] = makeMetaWhatsAppWebhookDelivery($companyA, 'wamid.ambiguous');
    ['delivery' => $deliveryB] = makeMetaWhatsAppWebhookDelivery($companyB, 'wamid.ambiguous');
    $body = metaWhatsAppWebhookBody(metaWhatsAppStatusPayload('wamid.ambiguous'));

    postMetaWhatsAppWebhook($this, $body, metaWhatsAppWebhookSignature($body), 'webhooks.whatsapp')
        ->assertNoContent();

    expect($deliveryA->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull()
        ->and($deliveryB->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->delivered_at->toBeNull();
});
