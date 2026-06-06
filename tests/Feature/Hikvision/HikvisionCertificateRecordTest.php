<?php

use App\Models\HikvisionAccessEvent;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;

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
    $stored = HikvisionAccessEvent::upsertFromCertificateRecord([
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
        'system_id' => 'attendance:EMP001:2026-06-05T08:00:00+04:00:checkIn',
        'occurrence_time' => '2026-06-05 08:00:00',
        'msg_type' => 'attendance/totaltimecard',
        'person_name' => 'Cert User',
        'attendance_status' => 'checkIn',
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'fetched_at' => now(),
    ]);

    $duplicate = HikvisionAccessEvent::upsertFromCertificateRecord([
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

    $count = app(HikvisionService::class)->fetchCertificateRecords(
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($count)->toBe(1)
        ->and(HikvisionAccessEvent::query()->where('event_source', HikvisionAccessEvent::EVENT_SOURCE_CERTIFICATE_API)->count())->toBe(1);
});
