<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function postHikvisionAccessEventsFetch(User $user): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.access-events.index'));

    return test()->actingAs($user)->post(route('hikvision.access-events.fetch'), [
        '_token' => csrf_token(),
    ]);
}

function runHikvisionAccessEventsFetchJob(): void
{
    (new FetchHikvisionAccessEventsJob)->handle(app(HikvisionService::class));
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
            ->has('filters')
            ->has('attendance_status_options')
            ->has('fetch_status')
            ->has('can'),
        );
});

test('access events can be filtered by name date and attendance status', function () {
    HikvisionAccessEvent::query()->create([
        'system_id' => 'test:dil:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-05 08:00:00',
        'person_name' => 'Dil',
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkIn',
        'event_source' => 'acs_isapi',
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'test:maysa:checkout',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-06-04 17:00:00',
        'person_name' => 'maysa',
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkOut',
        'event_source' => 'acs_isapi',
        'fetched_at' => now(),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index', [
            'search' => 'Dil',
            'date_from' => '2026-06-05',
            'date_to' => '2026-06-05',
            'attendance_status' => 'checkIn',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.person_name', 'Dil')
            ->where('filters.search', 'Dil')
            ->where('filters.attendance_status', 'checkIn'),
        );
});

test('user without permission cannot view hikvision access events page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertForbidden();
});

test('fetch dispatches background job instead of running synchronously', function () {
    Queue::fake();
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
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
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob();

    expect(HikvisionAccessEvent::query()->count())->toBe(1)
        ->and(HikvisionAccessEvent::query()->value('person_name'))->toBe('Dil')
        ->and(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_COMPLETED);
});

test('background job stores acs access records from isapi proxypass', function () {
    fakeHikvisionAcsEventsFetch();
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob();

    expect(HikvisionAccessEvent::query()->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Dil')->value('attendance_status'))->toBe('checkIn')
        ->and(HikvisionAccessEvent::query()->where('person_name', 'maysa')->value('device_name'))->toBe('OMS-Door')
        ->and(HikvisionSetting::current()->events_last_fetched_at)->not->toBeNull()
        ->and(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_COMPLETED);

    Http::assertSent(fn ($request) => $request->url() === 'https://isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass'
        && ($request['id'] ?? null) === 'device-acs-1');
});

test('fetch fails when hikvision is not configured', function () {
    Queue::fake();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user)
        ->assertRedirect()
        ->assertSessionHasErrors('fetch');

    Queue::assertNothingPushed();
});

test('background job fails when no access controller devices exist', function () {
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
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob();

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_FAILED)
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('No access controller devices found');
});

test('index acknowledges completed fetch and resets status to idle', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    HikvisionSetting::current()->markEventsFetchCompleted('Fetched 5 access record(s) for today.');

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fetch_status', HikvisionSetting::EVENTS_FETCH_COMPLETED)
            ->where('fetch_message', 'Fetched 5 access record(s) for today.'),
        );

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_IDLE);
});

test('index marks stale queued fetch as failed', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    $settings = HikvisionSetting::current();
    $settings->update([
        'events_fetch_status' => HikvisionSetting::EVENTS_FETCH_QUEUED,
        'events_fetch_started_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fetch_status', HikvisionSetting::EVENTS_FETCH_FAILED)
            ->where('fetch_message', 'Fetch timed out. Confirm the server queue worker (cron) is running.'),
        );
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
