<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\SyncHikvisionAttendanceJob;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Services\HikvisionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

afterEach(function () {
    Carbon::setTestNow();
});

function freezeHikvisionAccessEventFetchDate(): void
{
    Carbon::setTestNow('2026-06-05 10:00:00', config('app.timezone'));
}

function postHikvisionAccessEventsFetch(User $user, ?string $date = null): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.access-events.index'));

    $payload = ['_token' => csrf_token()];

    if ($date !== null) {
        $payload['date'] = $date;
    }

    return test()->actingAs($user)->post(route('hikvision.access-events.fetch'), $payload);
}

function runHikvisionAccessEventsFetchJob(?string $date = null): void
{
    $hikvision = app(HikvisionService::class);

    (new FetchHikvisionAccessEventsJob($date))->handle($hikvision);
    (new SyncHikvisionAttendanceJob($date))->handle($hikvision);
}

function fakeHikvisionAcsEventsFetch(array $attendanceReportDataList = []): void
{
    freezeHikvisionAccessEventFetchDate();

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
        'isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list' => Http::response([
            'data' => [
                'pageIndex' => 1,
                'pageSize' => 200,
                'moreData' => 0,
                'reportDataList' => $attendanceReportDataList,
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => [],
                'totalNum' => 0,
            ],
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
            ->has('device_options')
            ->has('fetch_status')
            ->has('can'),
        );
});

test('access events page resolves hikvision person photo by name when person id is missing', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    HikvisionPerson::query()->create([
        'person_id' => 'person-maher-id',
        'full_name' => 'Maher',
        'photo_path' => 'https://example.com/maher-headshot.jpg',
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'acs:photo-by-name',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-08 09:00:00',
        'person_name' => 'Maher',
        'person_hikvision_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkIn',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'snap_urls' => ['https://example.com/expired-snap.jpg'],
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.person_photo_url', 'https://example.com/maher-headshot.jpg'),
        );
});

test('access events page falls back to hikvision person photo when snap url is missing', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    HikvisionPerson::query()->create([
        'person_id' => 'person-photo-fallback',
        'full_name' => 'Maysa',
        'photo_path' => 'https://example.com/person-headshot.jpg',
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'acs:photo-fallback',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-08 08:08:00',
        'person_name' => 'Maysa',
        'person_hikvision_id' => 'person-photo-fallback',
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkIn',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'snap_urls' => [],
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.snap_urls', [])
            ->where('events.0.person_photo_url', 'https://example.com/person-headshot.jpg'),
        );
});

test('access events page includes certificate-backed events with snap urls', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'cert:test-1',
        'msg_type' => 'acs/certificate-record',
        'occurrence_time' => '2026-06-05 08:30:00',
        'person_name' => 'Cert User',
        'person_hikvision_id' => 'person-cert-1',
        'attendance_status' => 'checkIn',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_CERTIFICATE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'snap_urls' => ['https://example.com/snap.jpg'],
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.event_source', HikvisionAccessEvent::EVENT_SOURCE_CERTIFICATE_API)
            ->where('events.0.snap_urls', ['https://example.com/snap.jpg']),
        );
});

test('access events can be filtered by device', function () {
    HikvisionAccessEvent::query()->create([
        'system_id' => 'test:dil:door',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-05 08:00:00',
        'person_name' => 'Dil',
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkIn',
        'event_source' => 'acs_isapi',
        'transaction_source' => 'device',
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'test:mathew:mobile',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-04 09:11:00',
        'person_name' => 'Mathew',
        'device_name' => 'Mobile App',
        'attendance_status' => 'checkIn',
        'event_source' => 'attendance_api',
        'transaction_source' => 'mobile_app',
        'fetched_at' => now(),
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index', ['device' => 'Mobile App']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.person_name', 'Mathew')
            ->where('filters.device', 'Mobile App'),
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

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, fn (FetchHikvisionAccessEventsJob $job): bool => $job->date === now(config('app.timezone'))->format('Y-m-d'));

    expect(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_QUEUED);
});

test('fetch dispatches background job for selected date', function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-08 12:00:00', config('app.timezone'));
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user, '2026-06-07')
        ->assertRedirect()
        ->assertSessionHas('success', 'Fetch started for 07-06-2026. Records will update automatically when complete.');

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, fn (FetchHikvisionAccessEventsJob $job): bool => $job->date === '2026-06-07');
});

test('background job fetches records for selected date window', function () {
    Carbon::setTestNow('2026-06-08 12:00:00', config('app.timezone'));

    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 0,
            'InfoList' => [],
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
        'isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass' => function ($request) use ($acsPayload) {
            $body = json_decode((string) $request->body(), true);
            $isapiBody = json_decode((string) ($body['body'] ?? ''), true);

            expect($isapiBody['AcsEventCond']['startTime'] ?? null)->toBe('2026-06-07T00:00:00+04:00')
                ->and($isapiBody['AcsEventCond']['endTime'] ?? null)->toBe('2026-06-07T23:59:59+04:00');

            return Http::response([
                'data' => $acsPayload,
                'errorCode' => '0',
            ], 200);
        },
        'isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list' => function ($request) {
            $body = json_decode((string) $request->body(), true);

            expect($body['beginTime'] ?? null)->toBe('2026-06-07T00:00:00+04:00')
                ->and($body['endTime'] ?? null)->toBe('2026-06-07T23:59:59+04:00');

            return Http::response([
                'data' => [
                    'pageIndex' => 1,
                    'pageSize' => 200,
                    'moreData' => 0,
                    'reportDataList' => [],
                ],
                'errorCode' => '0',
            ], 200);
        },
    ]);

    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-07');

    expect(HikvisionSetting::current()->events_fetch_message)->toBe('Fetched 0 access record(s) for 2026-06-07 (0 device, 0 mobile app).');
});

test('fetch rejects future dates', function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-08 12:00:00', config('app.timezone'));
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.events.view',
        'hikvision.events.fetch',
    ]);

    postHikvisionAccessEventsFetch($user, '2026-06-09')
        ->assertSessionHasErrors('date');

    Queue::assertNothingPushed();
});

test('fetch ignores door system events without person identity', function () {
    freezeHikvisionAccessEventFetchDate();

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
        'isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list' => Http::response([
            'data' => [
                'pageIndex' => 1,
                'pageSize' => 200,
                'moreData' => 0,
                'reportDataList' => [],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => [],
                'totalNum' => 0,
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-05');

    expect(HikvisionAccessEvent::query()->count())->toBe(1)
        ->and(HikvisionAccessEvent::query()->value('person_name'))->toBe('Dil')
        ->and(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_COMPLETED);
});

test('background job stores mobile app attendance records from total time card api', function () {
    freezeHikvisionAccessEventFetchDate();

    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 0,
            'InfoList' => [],
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
        'isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list' => Http::response([
            'data' => [
                'pageIndex' => 1,
                'pageSize' => 200,
                'moreData' => 0,
                'reportDataList' => [
                    [
                        'fullName' => 'Mathew',
                        'personCode' => '7',
                        'clockInDate' => '2026/06/05',
                        'clockInTime' => '09:11:44',
                        'clockInSource' => 3,
                        'clockInDevice' => '',
                        'clockOutDate' => '2026/06/05',
                        'clockOutTime' => '17:55:12',
                        'clockOutSource' => 3,
                        'clockOutDevice' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => [],
                'totalNum' => 0,
            ],
            'errorCode' => '0',
        ], 200),
    ]);
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-05');

    expect(HikvisionAccessEvent::query()->where('transaction_source', 'mobile_app')->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Mathew')->where('attendance_status', 'checkIn')->exists())->toBeTrue()
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Mathew')->where('attendance_status', 'checkOut')->exists())->toBeTrue()
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('mobile app')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('for today');

    Http::assertSent(fn ($request) => $request->url() === 'https://isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list');
});

test('background job stores person hikvision id on mobile app access events when person code is linked', function () {
    freezeHikvisionAccessEventFetchDate();

    HikvisionPerson::query()->create([
        'person_id' => 'hv-person-7',
        'person_code' => '7',
        'full_name' => 'Mathew',
    ]);

    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 0,
            'InfoList' => [],
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
        'isgp.hikcentralconnect.com/api/hccgw/attendance/v1/report/totaltimecard/list' => Http::response([
            'data' => [
                'pageIndex' => 1,
                'pageSize' => 200,
                'moreData' => 0,
                'reportDataList' => [
                    [
                        'fullName' => 'Mathew',
                        'personCode' => '7',
                        'clockInDate' => '2026/06/05',
                        'clockInTime' => '09:11:44',
                        'clockInSource' => 3,
                        'clockInDevice' => '',
                        'clockOutDate' => '2026/06/05',
                        'clockOutTime' => '17:55:12',
                        'clockOutSource' => 3,
                        'clockOutDevice' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => [],
                'totalNum' => 0,
            ],
            'errorCode' => '0',
        ], 200),
    ]);
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-05');

    expect(HikvisionAccessEvent::query()->where('transaction_source', 'mobile_app')->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('person_hikvision_id', 'hv-person-7')->count())->toBe(2);
});

test('background job stores acs access records from isapi proxypass', function () {
    fakeHikvisionAcsEventsFetch();
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-05');

    expect(HikvisionAccessEvent::query()->where('event_source', 'acs_isapi')->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Dil')->value('attendance_status'))->toBe('checkIn')
        ->and(HikvisionAccessEvent::query()->where('person_name', 'Dil')->value('transaction_source'))->toBe('device')
        ->and(HikvisionAccessEvent::query()->where('person_name', 'maysa')->value('device_name'))->toBe('OMS-Door')
        ->and(HikvisionSetting::current()->events_last_fetched_at)->not->toBeNull()
        ->and(HikvisionSetting::current()->events_fetch_status)->toBe(HikvisionSetting::EVENTS_FETCH_COMPLETED);

    Http::assertSent(fn ($request) => $request->url() === 'https://isgp.hikcentralconnect.com/api/hccgw/video/v1/isapi/proxypass'
        && ($request['id'] ?? null) === 'device-acs-1');
});

test('daily fetch does not call certificate records api', function () {
    freezeHikvisionAccessEventFetchDate();
    fakeHikvisionAcsEventsFetch();
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-05');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'certificaterecords/search'));
    expect(HikvisionSetting::current()->events_fetch_message)->not->toContain('certificate');
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

    runHikvisionAccessEventsFetchJob('2026-06-05');

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

test('access events page does not expose employees linked in another company', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.events.view']);

    $viewerCompany = $user->companies()->first();

    $otherCompany = Company::query()->create([
        'name' => 'Other Events Co',
        'slug' => 'other-events-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $viewerCompany->country_id,
        'currency_id' => $viewerCompany->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'name' => 'Secret Other Co Employee',
        ]);

    linkHikvisionPersonToUserCompany($otherEmployee, 'cross-company-person-id');

    HikvisionAccessEvent::query()->create([
        'system_id' => 'acs:cross-company',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-08 09:00:00',
        'person_name' => 'Secret Other Co Employee',
        'person_hikvision_id' => 'cross-company-person-id',
        'device_name' => 'OMS-Door',
        'attendance_status' => 'checkIn',
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.access-events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.person_name', 'Secret Other Co Employee')
            ->where('events.0.employee_name', null)
            ->where('events.0.employee_id', null),
        );
});

function fakeHikvisionEveningScheduledFetchJune11(): void
{
    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 1,
            'InfoList' => [
                [
                    'major' => 5,
                    'minor' => 38,
                    'time' => '2026-06-11T08:28:00+04:00',
                    'name' => 'Employee One',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                    'attendanceStatus' => 'checkIn',
                ],
            ],
        ],
    ]);

    $june11MobileRow = [
        'fullName' => 'Employee Two',
        'personCode' => '42',
        'clockInDate' => '2026/06/11',
        'clockInTime' => '09:00:00',
        'clockInSource' => 3,
        'clockInDevice' => '',
        'clockOutDate' => '2026/06/11',
        'clockOutTime' => '18:00:00',
        'clockOutSource' => 3,
        'clockOutDevice' => '',
    ];

    Http::fake(function ($request) use ($acsPayload, $june11MobileRow) {
        $url = $request->url();

        if (str_contains($url, '/token/get')) {
            return Http::response([
                'data' => [
                    'accessToken' => 'hcc.test-token',
                    'expireTime' => 1781256540,
                    'userId' => 'user-123',
                    'areaDomain' => 'https://isgp.hikcentralconnect.com',
                ],
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/devices/get')) {
            return Http::response([
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
            ], 200);
        }

        if (str_contains($url, '/isapi/proxypass')) {
            return Http::response([
                'data' => $acsPayload,
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/totaltimecard/list')) {
            $beginTime = (string) ($request->data()['beginTime'] ?? '');
            $reportDataList = str_contains($beginTime, '2026-06-11') ? [] : [$june11MobileRow];

            return Http::response([
                'data' => [
                    'pageIndex' => 1,
                    'pageSize' => 200,
                    'moreData' => 0,
                    'reportDataList' => $reportDataList,
                ],
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/certificaterecords/search')) {
            return Http::response([
                'data' => [
                    'recordList' => [],
                    'totalNum' => 0,
                ],
                'errorCode' => '0',
            ], 200);
        }

        return Http::response(['errorCode' => '404'], 404);
    });
}

test('scheduled morning fetch stores yesterdays door events but zero mobile when hikconnect has not published them yet', function () {
    Carbon::setTestNow('2026-06-12 08:50:00', 'Asia/Dubai');

    fakeHikvisionEveningScheduledFetchJune11();
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob();

    expect(HikvisionAccessEvent::query()->where('transaction_source', 'device')->count())->toBe(1)
        ->and(HikvisionAccessEvent::query()->where('transaction_source', 'mobile_app')->count())->toBe(0)
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('Scheduled fetch:')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('1 device, 0 mobile app')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('2026-06-11');
});

test('scheduled fetch backfills yesterdays mobile on the next mornings run', function () {
    Carbon::setTestNow('2026-06-12 08:50:00', 'Asia/Dubai');

    $acsPayload = json_encode([
        'AcsEvent' => [
            'searchID' => '1',
            'totalMatches' => 1,
            'InfoList' => [
                [
                    'major' => 5,
                    'minor' => 38,
                    'time' => '2026-06-11T08:28:00+04:00',
                    'name' => 'Employee One',
                    'doorNo' => 1,
                    'cardReaderNo' => 1,
                    'currentVerifyMode' => 'faceOrFpOrCardOrPw',
                    'attendanceStatus' => 'checkIn',
                ],
            ],
        ],
    ]);

    $june11MobileRow = [
        'fullName' => 'Employee Two',
        'personCode' => '42',
        'clockInDate' => '2026/06/11',
        'clockInTime' => '09:00:00',
        'clockInSource' => 3,
        'clockInDevice' => '',
        'clockOutDate' => '2026/06/11',
        'clockOutTime' => '18:00:00',
        'clockOutSource' => 3,
        'clockOutDevice' => '',
    ];

    Http::fake(function ($request) use ($acsPayload, $june11MobileRow) {
        $url = $request->url();

        if (str_contains($url, '/token/get')) {
            return Http::response([
                'data' => [
                    'accessToken' => 'hcc.test-token',
                    'expireTime' => 1781256540,
                    'userId' => 'user-123',
                    'areaDomain' => 'https://isgp.hikcentralconnect.com',
                ],
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/devices/get')) {
            return Http::response([
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
            ], 200);
        }

        if (str_contains($url, '/isapi/proxypass')) {
            return Http::response([
                'data' => $acsPayload,
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/totaltimecard/list')) {
            $beginTime = (string) ($request->data()['beginTime'] ?? '');
            $reportDataList = str_contains($beginTime, '2026-06-11') ? [$june11MobileRow] : [];

            return Http::response([
                'data' => [
                    'pageIndex' => 1,
                    'pageSize' => 200,
                    'moreData' => 0,
                    'reportDataList' => $reportDataList,
                ],
                'errorCode' => '0',
            ], 200);
        }

        if (str_contains($url, '/certificaterecords/search')) {
            return Http::response([
                'data' => [
                    'recordList' => [],
                    'totalNum' => 0,
                ],
                'errorCode' => '0',
            ], 200);
        }

        return Http::response(['errorCode' => '404'], 404);
    });

    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob();

    expect(HikvisionAccessEvent::query()->where('transaction_source', 'mobile_app')->count())->toBe(2)
        ->and(HikvisionAccessEvent::query()->where('transaction_source', 'device')->count())->toBe(1)
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('Scheduled fetch:')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('2026-06-11')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('2 mobile app');
});

test('manual fetch for a specific date stores mobile once hikconnect publishes that days records', function () {
    Carbon::setTestNow('2026-06-12 09:38:00', 'Asia/Dubai');

    fakeHikvisionAcsEventsFetch([
        [
            'fullName' => 'Employee Two',
            'personCode' => '42',
            'clockInDate' => '2026/06/11',
            'clockInTime' => '09:00:00',
            'clockInSource' => 3,
            'clockInDevice' => '',
            'clockOutDate' => '2026/06/11',
            'clockOutTime' => '18:00:00',
            'clockOutSource' => 3,
            'clockOutDevice' => '',
        ],
    ]);
    configuredHikvisionSettings();
    HikvisionSetting::current()->beginEventsFetch();

    runHikvisionAccessEventsFetchJob('2026-06-11');

    expect(HikvisionAccessEvent::query()->where('transaction_source', 'mobile_app')->count())->toBe(2)
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('2 mobile app')
        ->and(HikvisionSetting::current()->events_fetch_message)->toContain('2026-06-11');
});
