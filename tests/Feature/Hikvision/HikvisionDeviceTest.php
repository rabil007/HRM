<?php

use App\Models\HikvisionDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function postHikvisionDevicesSync(User $user): TestResponse
{
    test()->actingAs($user)->get(route('application.edit'));

    return test()->actingAs($user)->post(route('application.hikvision.devices.sync'), [
        '_token' => csrf_token(),
    ]);
}

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

    test()->actingAs($user)->get(route('application.edit'));

    test()->actingAs($user)
        ->post(route('application.hikvision.devices.sync'), ['_token' => csrf_token()])
        ->assertForbidden();
});
