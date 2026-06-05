<?php

use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function postHikvisionAccessEventsFetch(User $user): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.access-events.index'));

    return test()->actingAs($user)->post(route('hikvision.access-events.fetch'), [
        '_token' => csrf_token(),
    ]);
}

function fakeHikvisionAcsEventsFetch(): void
{
    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 2,
            'InfoList' => [
                [
                    'major' => 5,
                    'minor' => 38,
                    'time' => '2026-06-05T07:20:14+04:00',
                    'name' => 'Dil',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                    'attendanceStatus' => 'checkIn',
                ],
                [
                    'major' => 5,
                    'minor' => 75,
                    'time' => '2026-06-05T07:52:31+04:00',
                    'name' => 'maysa',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                    'attendanceStatus' => 'checkIn',
                ],
            ],
        ],
    ]);

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
                        'id' => 'device-acs-1',
                        'name' => 'OMS-Door',
                        'serialNo' => 'FZ4480436',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass' => Http::response([
            'data' => $acsPayload,
            'errorCode' => '0',
        ], 200),
    ]);
}

test('user with permission can view hikvision access events page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/access-events')
            ->has('events')
            ->has('pagination')
            ->has('can'),
        );
});

test('user without permission cannot view hikvision access events page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertForbidden();
});

test('fetch ignores door system events without person identity', function () {
    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 3,
            'InfoList' => [
                [
                    'major' => 5,
                    'minor' => 21,
                    'time' => '2026-06-05T07:20:14+04:00',
                    'doorNo' => 1,
                    'currentVerifyMode' => 'invalid',
                ],
                [
                    'major' => 5,
                    'minor' => 38,
                    'time' => '2026-06-05T07:20:15+04:00',
                    'name' => 'Dil',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                    'attendanceStatus' => 'checkIn',
                ],
                [
                    'major' => 5,
                    'minor' => 76,
                    'time' => '2026-06-05T07:20:16+04:00',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                ],
            ],
        ],
    ]);

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
                        'id' => 'device-acs-1',
                        'name' => 'OMS-Door',
                        'serialNo' => 'FZ4480436',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass' => Http::response([
            'data' => $acsPayload,
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionAccessEvent::query()->count())->toBe(1)
        ->and(HikvisionAccessEvent::query()->value('person_name'))->toBe('Dil');
});

test('fetch stores acs access records from isapi proxypass', function () {
    fakeHikvisionAcsEventsFetch();
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionAccessEvent::query()->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Dil')->value('attendance_status'))->toBe('checkIn')
        ->and(HikvisionAccessEvent::query()->where('person_name', 'maysa')->value('device_name'))->toBe('OMS-Door')
        ->and(HikvisionSetting::current()->events_last_fetched_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass'
        && ($request['id'] ?? null) === 'device-acs-1');
});

test('fetch fails when hikvision is not configured', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHasErrors('fetch');
});

test('fetch fails when no access controller devices exist', function () {
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
                'totalCount' => 0,
                'pageIndex' => 1,
                'pageSize' => 50,
                'device' => [],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHasErrors('fetch');
});

test('user without fetch permission cannot fetch hikvision access events', function () {
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    test()->actingAs($user)->get(route('hikvision.access-events.index'));

    test()->actingAs($user)
        ->post(route('hikvision.access-events.fetch'), ['_token' => csrf_token()])
        ->assertForbidden();
});
