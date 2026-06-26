<?php

use App\Models\HikvisionDevice;
use App\Models\HikvisionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function hikvisionSettingsUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ], $overrides);
}

function actingAsWithCsrf(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('application.edit'));
}

/**
 * @param  array<string, mixed>  $payload
 */
function putHikvisionSettings(User $user, array $payload): TestResponse
{
    actingAsWithCsrf($user);

    return test()->actingAs($user)->put(route('application.hikvision.update'), [
        '_token' => csrf_token(),
        ...$payload,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postHikvisionConnectionTest(User $user, array $payload): TestResponse
{
    actingAsWithCsrf($user);

    return test()->actingAs($user)
        ->withHeader('X-CSRF-TOKEN', (string) csrf_token())
        ->postJson(route('application.hikvision.test'), $payload);
}

function postHikvisionDevicesSync(User $user): TestResponse
{
    actingAsWithCsrf($user);

    return test()->actingAs($user)->post(route('application.hikvision.devices.sync'), [
        '_token' => csrf_token(),
    ]);
}

test('owner can view hikvision integration settings page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/application')
            ->has('hikvision.settings')
            ->where('hikvision.settings.api_host', fn ($value) => is_string($value))
            ->has('hikvision.webhook_url')
            ->has('hikvision.scheduler_timezone')
            ->has('hikvision.settings.events_fetch_schedule_enabled')
            ->has('hikvision.settings.events_fetch_schedule_at')
            ->has('hikvision.settings.events_evening_fetch_schedule_enabled')
            ->has('hikvision.settings.events_evening_fetch_schedule_at'),
        );
});

test('user with webhook permission can register hikvision webhook', function () {
    configuredHikvisionSettings();

    Http::fake([
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/token/get' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/webhook/v1/config/save' => Http::response([
            'data' => [],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/rawmsg/v1/mq/subscribe' => Http::response([
            'data' => [],
            'errorCode' => '0',
        ], 200),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.webhook.manage',
    ]);

    $this->actingAs($user)
        ->from(route('application.edit'))
        ->post(route('application.hikvision.webhook.register'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionSetting::current()->webhook_registered_at)->not->toBeNull();
});

test('users without hikvision permission do not receive hikvision settings props', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.application.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/application')
            ->where('hikvision', null),
        );
});

test('users with hikvision permission can open application settings without application view', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('hikvision'));
});

test('hikvision settings can be saved with encrypted secrets', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    putHikvisionSettings($user, hikvisionSettingsUpdatePayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = HikvisionSetting::current();

    expect($settings->api_host)->toBe('https://isgp.hikcentralconnect.com')
        ->and($settings->api_key)->toBe('test-api-key')
        ->and($settings->api_secret)->toBe('test-api-secret')
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->isConfigured())->toBeTrue();

    $raw = HikvisionSetting::query()->find(1);

    expect($raw?->getRawOriginal('api_key'))->not->toBe('test-api-key')
        ->and($raw?->getRawOriginal('api_secret'))->not->toBe('test-api-secret');
});

test('hikvision secrets are kept when update omits them', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    HikvisionSetting::current()->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'keep-api-key',
        'api_secret' => 'keep-api-secret',
        'enabled' => true,
    ]);

    putHikvisionSettings($user, hikvisionSettingsUpdatePayload([
        'api_key' => '',
        'api_secret' => '',
    ]))->assertRedirect();

    $settings = HikvisionSetting::current();

    expect($settings->api_key)->toBe('keep-api-key')
        ->and($settings->api_secret)->toBe('keep-api-secret');
});

test('hikvision test connection returns success when api responds ok', function () {
    Http::fake([
        'isgp.hikcentralconnect.com/*' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    postHikvisionConnectionTest($user, [
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'valid-key',
        'api_secret' => 'valid-secret',
        'enabled' => true,
    ])->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Connection successful.',
        ]);
});

test('hikvision test connection returns error message on failure', function () {
    Http::fake([
        'isgp.hikcentralconnect.com/*' => Http::response([
            'message' => 'AK_NOT_FOUND{OPEN000001}',
            'errorCode' => 'OPEN000001',
        ], 200),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'settings.integrations.hikvision.update',
    ]);

    postHikvisionConnectionTest($user, [
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'invalid-key',
        'api_secret' => 'invalid-secret',
        'enabled' => true,
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'AK_NOT_FOUND{OPEN000001}',
        ]);
});

test('application settings page includes decrypted hikvision credentials', function () {
    HikvisionSetting::current()->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'stored-api-key',
        'api_secret' => 'stored-api-secret',
        'enabled' => true,
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hikvision.settings.api_key', 'stored-api-key')
            ->where('hikvision.settings.api_secret', 'stored-api-secret'),
        );
});

test('hikvision settings page reports env fallback when database credentials are empty', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    $settings = HikvisionSetting::current()->toSettingsPageArray();

    if (
        filled(env('HIKVISION_API_HOST'))
        && filled(env('HIKVISION_API_KEY'))
        && filled(env('HIKVISION_API_SECRET'))
    ) {
        expect($settings['uses_env_fallback'])->toBeTrue()
            ->and($settings['has_api_key'])->toBeTrue()
            ->and($settings['has_api_secret'])->toBeTrue()
            ->and($settings['api_host'])->toBe((string) env('HIKVISION_API_HOST'));
    } else {
        expect($settings['uses_env_fallback'])->toBeFalse();
    }
});

test('user with permission sees devices on hikvision settings tab', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.devices.view',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/application')
            ->has('hikvision.devices.items')
            ->has('hikvision.devices.can'),
        );
});

test('user without devices permission does not receive devices props', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.integrations.hikvision.view']);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/application')
            ->missing('hikvision.devices'),
        );
});

test('legacy devices url redirects to hikvision settings tab', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.devices.view']);

    $this->actingAs($user)
        ->get('/hikvision/devices')
        ->assertRedirect('/settings/application?tab=hikvision');
});

test('sync upserts devices and detail from hikvision api', function () {
    Http::fake([
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/token/get' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/resource/v1/devices/get' => Http::response([
            'data' => [
                'totalCount' => 1,
                'pageIndex' => 1,
                'pageSize' => 50,
                'device' => [
                    [
                        'id' => 'device-1',
                        'name' => 'Main Door',
                        'category' => 'encodingDevice',
                        'type' => 'DS-K1T671M',
                        'serialNo' => 'K12345678',
                        'onlineStatus' => 1,
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/resource/v1/devicedetail/get' => Http::response([
            'data' => [
                'device' => [
                    'baseInfo' => [
                        'id' => 'device-1',
                        'name' => 'Main Door',
                        'serialNo' => 'K12345678',
                    ],
                    'doorChannel' => [
                        ['id' => 'door-1', 'name' => 'Door 1', 'no' => '1'],
                    ],
                    'onlineStatus' => 1,
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.devices.view',
        'hikvision.devices.sync',
    ]);

    postHikvisionDevicesSync($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    $device = HikvisionDevice::query()->where('serial_no', 'K12345678')->first();

    expect(HikvisionDevice::query()->count())->toBe(1)
        ->and($device?->name)->toBe('Main Door')
        ->and($device?->raw_detail_payload)->toBeArray()
        ->and($device?->raw_detail_payload['doorChannel'] ?? null)->toHaveCount(1);
});

test('sync fails when hikvision is not configured', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.devices.view',
        'hikvision.devices.sync',
    ]);

    postHikvisionDevicesSync($user)
        ->assertRedirect()
        ->assertSessionHasErrors('devices_sync');
});

test('user without sync permission cannot sync hikvision devices', function () {
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.hikvision.view',
        'hikvision.devices.view',
    ]);

    actingAsWithCsrf($user);

    test()->actingAs($user)
        ->post(route('application.hikvision.devices.sync'), ['_token' => csrf_token()])
        ->assertForbidden();
});
