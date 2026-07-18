<?php

use App\Models\HikvisionAccessEvent;
use App\Services\HikvisionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Carbon::setTestNow();
});

function fakeHikvisionTokenResponse(): array
{
    return [
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/token/get' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
    ];
}

function fakeHikvisionCertificateRecordsApi(array $records = []): void
{
    if ($records === []) {
        $records = [
            [
                'recordId' => 'cert-1',
                'personInfo' => [
                    'personId' => 'person-cert-1',
                    'personName' => 'Cert User',
                ],
                'occurTime' => now()->format('Y-m-d\TH:i:sP'),
                'attendanceStatus' => 'checkIn',
                'deviceName' => 'Main Door',
                'sourceType' => 1,
                'verifyMode' => 'faceOrFpOrCardOrPw',
                'acsSnapPicList' => [
                    ['picUrl' => 'https://example.com/snap-1.jpg'],
                ],
            ],
        ];
    }

    Http::fake([
        ...fakeHikvisionTokenResponse(),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => $records,
                'totalNum' => count($records),
            ],
            'errorCode' => '0',
        ], 200),
    ]);
}

test('certificate record upsert stores snap urls and person id', function () {
    $stored = HikvisionAccessEvent::upsertFromCertificateRecord(hikvisionTestCompany()->id, [
        'recordId' => 'cert-upsert-1',
        'personInfo' => [
            'personId' => 'hv-person-1',
            'personName' => 'Cert User',
        ],
        'occurTime' => '2026-06-05T08:00:00+04:00',
        'attendanceStatus' => 'checkIn',
        'deviceName' => 'Main Door',
        'sourceType' => 3,
        'verifyMode' => 'face',
        'acsSnapPicList' => [
            ['picUrl' => 'https://example.com/snap.jpg'],
        ],
    ]);

    expect($stored)->not->toBeNull()
        ->and($stored->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_CERTIFICATE_API)
        ->and($stored->transaction_source)->toBe(HikvisionAccessEvent::TRANSACTION_MOBILE_APP)
        ->and($stored->person_hikvision_id)->toBe('hv-person-1')
        ->and($stored->snap_urls)->toBe(['https://example.com/snap.jpg']);
});

test('certificate record dedupe skips overlapping access records', function () {
    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => 'attendance:EMP001:2026-06-05T08:00:00+04:00:checkIn',
        'occurrence_time' => '2026-06-05 08:00:00',
        'msg_type' => 'attendance/totaltimecard',
        'person_name' => 'Cert User',
        'attendance_status' => 'checkIn',
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'fetched_at' => now(),
    ]);

    $duplicate = HikvisionAccessEvent::upsertFromCertificateRecord(hikvisionTestCompany()->id, [
        'recordId' => 'cert-dedupe-1',
        'personInfo' => [
            'personName' => 'Cert User',
        ],
        'occurTime' => '2026-06-05T08:00:00+04:00',
        'attendanceStatus' => 'checkIn',
        'sourceType' => 3,
    ]);

    expect($duplicate)->toBeNull()
        ->and(HikvisionAccessEvent::query()->count())->toBe(1);
});

test('fetch certificate records stores access events', function () {
    configuredHikvisionSettings();
    fakeHikvisionCertificateRecordsApi();

    $count = HikvisionService::forSetting(hikvisionSettings())->fetchCertificateRecords(
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($count)->toBe(1)
        ->and(HikvisionAccessEvent::query()->where('event_source', HikvisionAccessEvent::EVENT_SOURCE_CERTIFICATE_API)->count())->toBe(1);
});

test('certificate record upsert skips nameless records', function () {
    $stored = HikvisionAccessEvent::upsertFromCertificateRecord(hikvisionTestCompany()->id, [
        'recordId' => 'cert-nameless-1',
        'occurTime' => '2026-06-08T08:00:00+04:00',
        'attendanceStatus' => '1',
        'deviceName' => 'OMS-Door',
    ]);

    expect($stored)->toBeNull();
});

test('certificate record upsert resolves hik connect person info shape', function () {
    Carbon::setTestNow('2026-06-08 10:00:00', config('app.timezone'));

    $stored = HikvisionAccessEvent::upsertFromCertificateRecord(hikvisionTestCompany()->id, [
        'recordGuid' => 'cert-hcc-shape-1',
        'occurTime' => '2026-06-08T05:58:20Z',
        'deviceTime' => '2026-06-08T09:58:20+04:00',
        'attendanceStatus' => 1,
        'deviceName' => 'OMS-Door',
        'personInfo' => [
            'id' => '658668617248164865',
            'baseInfo' => [
                'firstName' => 'Capt.',
                'lastName' => 'Wael',
            ],
        ],
    ], now()->startOfDay(), now()->endOfDay());

    expect($stored)->not->toBeNull()
        ->and($stored->person_name)->toBe('Capt. Wael')
        ->and($stored->person_hikvision_id)->toBe('658668617248164865')
        ->and($stored->attendance_status)->toBe(HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
        ->and($stored->occurrence_time->format('Y-m-d H:i:s'))->toBe('2026-06-08 09:58:20');
});

test('fetch certificate records ignores historical api responses outside today window', function () {
    Carbon::setTestNow('2026-06-08 10:00:00', config('app.timezone'));
    configuredHikvisionSettings();

    $historicalRecords = array_fill(0, 100, [
        'recordId' => 'cert-historical',
        'occurTime' => '2025-11-20T08:00:00+04:00',
        'attendanceStatus' => '1',
        'deviceName' => 'OMS-Door',
    ]);

    Http::fake([
        ...fakeHikvisionTokenResponse(),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => $historicalRecords,
                'totalNum' => 8820,
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    $count = HikvisionService::forSetting(hikvisionSettings())->fetchCertificateRecords(
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($count)->toBe(0)
        ->and(HikvisionAccessEvent::query()->count())->toBe(0);

    Http::assertSentCount(2);
});

test('fetch certificate records stores only todays named records from mixed api response', function () {
    Carbon::setTestNow('2026-06-08 10:00:00', config('app.timezone'));
    configuredHikvisionSettings();

    Http::fake([
        ...fakeHikvisionTokenResponse(),
        'isgp.hikcentralconnect.com/api/hccgw/acs/v1/event/certificaterecords/search' => Http::response([
            'data' => [
                'recordList' => [
                    [
                        'recordId' => 'cert-old',
                        'occurTime' => '2025-11-20T08:00:00+04:00',
                        'attendanceStatus' => 'checkIn',
                        'personInfo' => ['personName' => 'Old User'],
                        'sourceType' => 1,
                    ],
                    [
                        'recordId' => 'cert-today',
                        'occurTime' => '2026-06-08T08:00:00+04:00',
                        'attendanceStatus' => 'checkIn',
                        'personInfo' => ['personName' => 'Today User'],
                        'sourceType' => 1,
                    ],
                ],
                'totalNum' => 2,
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    $count = HikvisionService::forSetting(hikvisionSettings())->fetchCertificateRecords(
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($count)->toBe(1)
        ->and(HikvisionAccessEvent::query()->value('person_name'))->toBe('Today User');
});
