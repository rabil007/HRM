<?php

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Jobs\SyncCompanyHikvisionAttendanceJob;
use App\Jobs\SyncHikvisionAttendanceJob;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use App\Support\Hikvision\HikvisionFetchOrigin;
use App\Support\Hikvision\HikvisionWebhookSignature;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

test('webhook verification get returns signature header', function () {
    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $timestamp = (string) time();
    $batchId = 'verification-batch-1';

    $this->get(route('webhooks.hikvision', hikvisionSettings()->public_id), [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
    ])->assertOk()
        ->assertHeader('X-Hook-Signature', HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId));
});

test('webhook rejects requests with invalid verify token', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
    ]);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), [
        'personInfo' => ['personName' => 'Webhook User'],
        'occurTime' => now()->toIso8601String(),
        'attendanceStatus' => 'checkIn',
    ], [
        'X-HCC-Webhook-Token' => 'wrong-token',
    ])->assertNotFound();

    Queue::assertNothingPushed();
});

test('webhook rejects requests when ingestion is disabled', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => false,
    ]);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), [
        'personName' => 'Disabled Webhook User',
        'attendanceStatus' => 'checkIn',
        'occurTime' => now()->toIso8601String(),
    ], [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNotFound();

    Queue::assertNothingPushed();
});

test('webhook dispatches job when signed post is valid', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $payload = [
        'batchId' => 'signed-batch-1',
        'personInfo' => [
            'personId' => 'person-webhook-2',
            'personName' => 'Signed Webhook User',
        ],
        'occurTime' => '2026-06-05T09:15:00+04:00',
        'attendanceStatus' => 'checkIn',
    ];

    $timestamp = (string) time();
    $batchId = 'signed-batch-1';
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});

test('webhook accepts signed post with millisecond timestamp', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $payload = [
        'batchId' => 'signed-batch-ms',
        'personInfo' => [
            'personId' => 'person-webhook-ms',
            'personName' => 'Millisecond Timestamp User',
        ],
        'occurTime' => '2026-06-08T09:00:00+04:00',
        'attendanceStatus' => 'checkIn',
    ];

    $batchId = 'signed-batch-ms';
    $timestamp = (string) (time() * 1000);
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});

test('get-minted signature with forged body does not create trusted attendance data', function () {
    Queue::fake();

    $settings = hikvisionSettings();
    $settings->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'forged-person-1',
        'full_name' => 'Forged Webhook User',
    ]);

    Employee::factory()->for(hikvisionTestCompany())->create([
        'status' => 'active',
        'name' => 'Forged Webhook User',
        'hikvision_person_id' => $person->id,
    ]);

    $batchId = 'oracle-batch-1';
    $timestamp = (string) time();

    $verification = $this->get(route('webhooks.hikvision', $settings->public_id), [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
    ])->assertOk();

    $signature = (string) $verification->headers->get('X-Hook-Signature');

    $forgedPayload = [
        'batchId' => $batchId,
        'personInfo' => [
            'personId' => 'forged-person-1',
            'personName' => 'Forged Webhook User',
        ],
        'occurTime' => now()->toIso8601String(),
        'attendanceStatus' => 'checkIn',
    ];

    $this->postJson(route('webhooks.hikvision', $settings->public_id), $forgedPayload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);

    (new ProcessHikvisionWebhookEventJob($forgedPayload, $settings->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0)
        ->and(AttendanceRecord::query()->count())->toBe(0);

    $timezone = CompanyTimezone::forCompany((int) $settings->company_id);
    $targetDate = now($timezone)->toDateString();

    Queue::assertPushed(
        FetchHikvisionAccessEventsJob::class,
        fn (FetchHikvisionAccessEventsJob $job): bool => $job->hikvisionSettingId === $settings->id
            && $job->date === $targetDate
            && $job->origin === HikvisionFetchOrigin::WebhookTrigger,
    );
});

test('valid signed webhook does not sync attendance from payload', function () {
    Queue::fake();

    $settings = hikvisionSettings();
    $settings->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $payload = [
        'batchId' => 'no-direct-sync-batch',
        'personInfo' => [
            'personId' => 'person-no-sync',
            'personName' => 'No Direct Sync User',
        ],
        'occurTime' => '2026-06-05T09:15:00+04:00',
        'attendanceStatus' => 'checkIn',
    ];

    $timestamp = (string) time();
    $batchId = 'no-direct-sync-batch';
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision', $settings->public_id), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    (new ProcessHikvisionWebhookEventJob($payload, $settings->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0)
        ->and(AttendanceRecord::query()->count())->toBe(0);

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class);
    Queue::assertNotPushed(SyncHikvisionAttendanceJob::class);
    Queue::assertNotPushed(SyncCompanyHikvisionAttendanceJob::class);
});

test('accessRecords excludes webhook event source', function () {
    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => 'webhook-historical-1',
        'msg_type' => 'webhook/event/110013',
        'occurrence_time' => '2026-06-08 09:00:00',
        'person_name' => 'Historical Webhook',
        'person_hikvision_id' => 'hist-webhook-1',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => 'acs-trusted-1',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-06-08 09:05:00',
        'person_name' => 'Trusted ACS',
        'person_hikvision_id' => 'acs-1',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    expect(HikvisionAccessEvent::query()->accessRecords()->pluck('system_id')->all())
        ->toBe(['acs-trusted-1']);
});

test('historical webhook-sourced event cannot affect attendance', function () {
    $person = HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => 'hist-attend-person',
        'full_name' => 'Historical Webhook Employee',
    ]);

    $employee = Employee::factory()->for(hikvisionTestCompany())->create([
        'status' => 'active',
        'name' => 'Historical Webhook Employee',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => 'webhook-only-punch',
        'msg_type' => 'webhook/event/110013',
        'occurrence_time' => '2026-06-08 08:30:00',
        'person_name' => 'Historical Webhook Employee',
        'person_hikvision_id' => 'hist-attend-person',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        hikvisionTestCompany()->id,
        Carbon::parse('2026-06-08 00:00:00'),
        Carbon::parse('2026-06-08 23:59:59'),
    );

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in)->toBeNull()
        ->and($record->clock_out)->toBeNull()
        ->and($record->status)->toBe(AttendanceRecord::STATUS_ABSENT)
        ->and($record->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC);
});

test('forged webhook matching trusted acs event cannot mutate that event', function () {
    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => '2bd7ecc491f8492f8ab20a3025538c63:2026-06-08T11:13:09+04:00:5:75:1:Mohammed Rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-06-08 11:13:09',
        'device_id' => '2bd7ecc491f8492f8ab20a3025538c63',
        'device_name' => 'OMS-Door',
        'resource_name' => 'Door 1',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => 'original-person-id',
        'door_no' => '1',
        'card_reader_no' => '1',
        'verify_mode' => 'faceOrFpOrCardOrPw',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'snap_urls' => null,
        'raw_payload' => [
            'serialNo' => 99552,
            'name' => 'Mohammed Rabil',
            'doorNo' => 1,
            'cardReaderNo' => 1,
        ],
        'fetched_at' => now(),
    ]);

    $payload = productionOpenDoorWebhookPayload(
        serialNo: 99552,
        occurrenceTime: '2026-06-08T11:13:09+04:00',
        personId: 'attacker-person-id',
        firstName: 'Attacker',
        lastName: 'Name',
        fullPath: 'IT',
    );

    $result = HikvisionAccessEvent::upsertFromWebhook($payload, hikvisionTestCompany()->id);

    expect(HikvisionAccessEvent::query()->count())->toBe(1);

    $event = HikvisionAccessEvent::query()->first();

    expect($result?->is($event))->toBeTrue()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI)
        ->and($event->person_hikvision_id)->toBe('original-person-id')
        ->and($event->person_name)->toBe('Mohammed Rabil')
        ->and($event->snap_urls)->toBeNull();
});

test('webhook-trigger fetch uniqueness coalesces bursts for same setting and date', function () {
    Queue::fake();

    $settings = hikvisionSettings();
    $timezone = CompanyTimezone::forCompany((int) $settings->company_id);
    $targetDate = now($timezone)->toDateString();

    FetchHikvisionAccessEventsJob::dispatch(
        $settings->id,
        $targetDate,
        HikvisionFetchOrigin::WebhookTrigger,
    );
    FetchHikvisionAccessEventsJob::dispatch(
        $settings->id,
        $targetDate,
        HikvisionFetchOrigin::WebhookTrigger,
    );

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, 1);

    // Manual reconciliation must remain reliable and not share the webhook lock.
    FetchHikvisionAccessEventsJob::dispatch(
        $settings->id,
        $targetDate,
        HikvisionFetchOrigin::Manual,
    );

    Queue::assertPushed(FetchHikvisionAccessEventsJob::class, 2);
});

test('webhook parser still maps hik-connect list envelope when called directly', function () {
    $payload = [
        'batchId' => '406c44ec5ac34d72842f8c724b5c6684',
        'list' => [
            [
                'type' => 'event',
                'basicInfo' => [
                    'device' => [
                        'id' => 'ac56cc2674d645d6b91313aeaa7c07da',
                        'name' => 'OMS-Door',
                        'category' => 'accessControllerDevice',
                        'deviceSerial' => 'FZ4488436',
                    ],
                    'systemId' => '593fbd35224641bb8acc3305cd9cfd9a',
                    'eventType' => '110013',
                    'occurrenceTime' => '2026-06-08T09:01:54+04:00',
                ],
                'data' => [
                    'openDoorInfo' => [
                        'event' => [
                            'basicInfo' => [
                                'deviceId' => 'ac56cc2674d645d6b91313aeaa7c07da',
                                'deviceName' => 'OMS-Door',
                                'occurTime' => '2026-06-08T09:01:54+04:00',
                                'systemId' => '593fbd35224641bb8acc3305cd9cfd9a',
                            ],
                            'intelliInfo' => [
                                'firstName' => 'maysa',
                                'lastName' => '',
                                'personId' => '549648292066532352',
                                'attendanceStatus' => 0,
                                'authResult' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $event = HikvisionAccessEvent::upsertFromWebhook($payload, hikvisionTestCompany()->id);

    expect($event)->not->toBeNull()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK)
        ->and($event->person_name)->toBe('maysa')
        ->and($event->person_hikvision_id)->toBe('549648292066532352');

    expect(HikvisionAccessEvent::query()->accessRecords()->whereKey($event->id)->exists())->toBeFalse();
});

test('webhook job updates last event timestamp via controller without storing body', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
        'webhook_last_event_at' => null,
    ]);

    $payload = [
        'recordId' => 'webhook-cert-1',
        'personInfo' => [
            'personId' => 'person-webhook-1',
            'personName' => 'Webhook User',
        ],
        'occurTime' => '2026-06-05T09:15:00+04:00',
        'attendanceStatus' => 'checkIn',
        'deviceName' => 'Lobby',
        'sourceType' => 1,
    ];

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), $payload, [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);

    expect(hikvisionSettings()->fresh()->webhook_last_event_at)->not->toBeNull()
        ->and(HikvisionAccessEvent::query()->count())->toBe(0);
});

test('webhook ignores empty payloads without updating last event timestamp', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
        'webhook_last_event_at' => null,
    ]);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), [], [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNoContent();

    Queue::assertNothingPushed();
    expect(hikvisionSettings()->fresh()->webhook_last_event_at)->toBeNull();
});

test('legacy hikvision webhook route still accepts signed posts as triggers', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
        'webhook_callback_url' => 'https://hrm.overseas-ms.com/webhooks/hikvision',
    ]);

    $payload = [
        'batchId' => 'legacy-batch-1',
        'personInfo' => [
            'personId' => 'person-legacy-1',
            'personName' => 'Legacy Webhook User',
        ],
        'occurTime' => '2026-07-20T09:15:00+04:00',
        'attendanceStatus' => 'checkIn',
    ];

    $timestamp = (string) time();
    $batchId = 'legacy-batch-1';
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision.legacy'), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});

test('legacy hikvision webhook route returns not found when no enabled integration exists', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => false,
    ]);

    $this->postJson(route('webhooks.hikvision.legacy'), [
        'personName' => 'Should Not Store',
    ], [
        'X-HCC-Webhook-Token' => 'abc12345',
    ])->assertNotFound();

    Queue::assertNothingPushed();
});

/**
 * @return array<string, mixed>
 */
function productionOpenDoorWebhookPayload(
    int $serialNo,
    string $occurrenceTime,
    string $personId,
    string $firstName,
    ?string $lastName,
    string $fullPath,
): array {
    return [
        'batchId' => 'production-open-door-batch',
        'list' => [
            [
                'type' => 'event',
                'basicInfo' => [
                    'occurrenceTime' => $occurrenceTime,
                    'systemId' => 'be2e21fbf43340c881fdcf8a80d224f8',
                    'msgType' => 'Msg110013',
                    'device' => [
                        'id' => '2bd7ecc491f8492f8ab20a3025538c63',
                        'name' => 'OMS-Door',
                        'category' => 'accessControllerDevice',
                        'deviceSerial' => 'FZ4480436',
                    ],
                ],
                'data' => [
                    'openDoorInfo' => [
                        'event' => [
                            'basicInfo' => [
                                'systemId' => 'be2e21fbf43340c881fdcf8a80d224f8',
                                'eventType' => 110013,
                                'elementId' => 'bdf91bfaa40b459c86a4d5cd5fd08edb',
                                'elementType' => 1002,
                                'elementName' => 'OMS-Door',
                                'occurTime' => $occurrenceTime,
                                'deviceId' => '2bd7ecc491f8492f8ab20a3025538c63',
                                'deviceSerial' => 'FZ4480436',
                                'deviceName' => 'OMS-Door',
                                'channelNo' => 0,
                                'currentEvent' => 0,
                                'serialNo' => $serialNo,
                                'cardReaderId' => '065ffb4bb3ed4290b29a467d08d5433a',
                            ],
                            'intelliInfo' => [
                                'personId' => $personId,
                                'firstName' => $firstName,
                                'lastName' => $lastName,
                                'fullPath' => $fullPath,
                                'personPicUrl' => 'https://example.com/person.jpg',
                                'attendanceStatus' => 1,
                                'authResult' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
