<?php

use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Support\Hikvision\HikvisionWebhookSignature;
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

test('webhook stores hik-connect list envelope access event', function () {
    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

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

    $batchId = '406c44ec5ac34d72842f8c724b5c6684';
    $timestamp = (string) (time() * 1000);
    $signature = HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId);

    $this->postJson(route('webhooks.hikvision', hikvisionSettings()->public_id), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK)
        ->and($event->person_name)->toBe('maysa')
        ->and($event->person_hikvision_id)->toBe('549648292066532352')
        ->and($event->device_name)->toBe('OMS-Door')
        ->and($event->attendance_status)->toBe(HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
        ->and($event->batch_id)->toBe($batchId)
        ->and($event->msg_type)->toBe('webhook/event/110013');

    hikvisionSettings()->refresh();
    expect(hikvisionSettings()->webhook_last_event_at)->not->toBeNull();
});

test('webhook maps production open door payload', function () {
    $payload = productionOpenDoorWebhookPayload(
        serialNo: 99552,
        occurrenceTime: '2026-06-08T11:13:09+04:00',
        personId: '705076684197985280',
        firstName: 'Mohammed',
        lastName: 'Rabil',
        fullPath: 'IT',
    );

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->person_name)->toBe('Mohammed Rabil')
        ->and($event->resource_name)->toBe('Door 1')
        ->and($event->door_no)->toBe('1')
        ->and($event->card_reader_no)->toBe('1')
        ->and($event->verify_mode)->toBe('face')
        ->and($event->attendance_status)->toBe(HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
        ->and($event->system_id)->toBe('webhook:2bd7ecc491f8492f8ab20a3025538c63:99552')
        ->and($event->snap_urls)->not->toBeNull();
});

test('webhook enriches existing acs row instead of creating duplicate', function () {
    HikvisionAccessEvent::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'system_id' => '2bd7ecc491f8492f8ab20a3025538c63:2026-06-08T11:13:09+04:00:5:75:1:Mohammed Rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-06-08 11:13:09',
        'device_id' => '2bd7ecc491f8492f8ab20a3025538c63',
        'device_name' => 'OMS-Door',
        'resource_name' => 'Door 1',
        'person_name' => 'Mohammed Rabil',
        'door_no' => '1',
        'card_reader_no' => '1',
        'verify_mode' => 'faceOrFpOrCardOrPw',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
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
        personId: '705076684197985280',
        firstName: 'Mohammed',
        lastName: 'Rabil',
        fullPath: 'IT',
    );

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(1);

    $event = HikvisionAccessEvent::query()->first();

    expect($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI)
        ->and($event->person_hikvision_id)->toBe('705076684197985280')
        ->and($event->resource_name)->toBe('Door 1')
        ->and($event->verify_mode)->toBe('faceOrFpOrCardOrPw')
        ->and($event->snap_urls)->not->toBeNull();
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

test('webhook resolves person name from synced hikvision person when only person id is sent', function () {
    HikvisionPerson::query()->create([
        'company_id' => hikvisionTestCompany()->id,
        'person_id' => '549648292066532352',
        'person_code' => '1',
        'first_name' => 'maysa',
        'last_name' => '',
        'full_name' => 'maysa',
        'synced_at' => now(),
    ]);

    $payload = [
        'batchId' => 'person-id-only-batch',
        'list' => [
            [
                'type' => 'event',
                'basicInfo' => [
                    'device' => ['id' => 'device-1', 'name' => 'OMS-Door'],
                    'systemId' => 'system-person-id-only',
                    'eventType' => '110013',
                    'occurrenceTime' => '2026-06-08T09:30:00+04:00',
                ],
                'data' => [
                    'openDoorInfo' => [
                        'event' => [
                            'basicInfo' => [
                                'deviceName' => 'OMS-Door',
                                'occurTime' => '2026-06-08T09:30:00+04:00',
                                'channelNo' => 1,
                            ],
                            'intelliInfo' => [
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

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->person_name)->toBe('maysa')
        ->and($event->person_hikvision_id)->toBe('549648292066532352')
        ->and($event->door_no)->toBe('1');
});

test('webhook skips failed authentication events', function () {
    $payload = [
        'batchId' => 'failed-auth-batch',
        'list' => [
            [
                'type' => 'event',
                'basicInfo' => [
                    'device' => ['id' => 'device-1', 'name' => 'OMS-Door'],
                    'systemId' => 'system-failed-auth',
                    'eventType' => '110013',
                    'occurrenceTime' => '2026-06-08T09:33:50+04:00',
                ],
                'data' => [
                    'openDoorInfo' => [
                        'event' => [
                            'basicInfo' => [
                                'deviceName' => 'OMS-Door',
                                'occurTime' => '2026-06-08T09:33:50+04:00',
                            ],
                            'intelliInfo' => [
                                'attendanceStatus' => 0,
                                'authResult' => 0,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0);
});

test('webhook skips sparse door events without successful authentication', function () {
    $payload = [
        'batchId' => 'sparse-door-batch',
        'list' => [
            [
                'type' => 'event',
                'basicInfo' => [
                    'device' => ['id' => 'device-1', 'name' => 'OMS-Door'],
                    'systemId' => 'system-sparse-door',
                    'eventType' => '110013',
                    'occurrenceTime' => '2026-06-08T09:24:59+04:00',
                ],
                'data' => [
                    'openDoorInfo' => [
                        'event' => [
                            'basicInfo' => [
                                'deviceName' => 'OMS-Door',
                                'occurTime' => '2026-06-08T09:24:59+04:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0);
});

test('webhook dispatches job and stores event with valid token', function () {
    Queue::fake();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
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

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class, function (ProcessHikvisionWebhookEventJob $job) use ($payload): bool {
        return $job->payload === $payload;
    });

    (new ProcessHikvisionWebhookEventJob($payload, hikvisionSettings()->id))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK)
        ->and($event->person_name)->toBe('Webhook User');

    hikvisionSettings()->refresh();
    expect(hikvisionSettings()->webhook_last_event_at)->not->toBeNull();
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
