<?php

use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionWebhookSignature;
use Illuminate\Support\Facades\Queue;

test('webhook verification get returns signature header', function () {
    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'abc12345',
    ]);

    $timestamp = (string) time();
    $batchId = 'verification-batch-1';

    $this->get(route('webhooks.hikvision'), [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
    ])->assertOk()
        ->assertHeader('X-Hook-Signature', HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId));
});

test('webhook rejects requests with invalid verify token', function () {
    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
    ]);

    $this->postJson(route('webhooks.hikvision'), [
        'personInfo' => ['personName' => 'Webhook User'],
        'occurTime' => now()->toIso8601String(),
        'attendanceStatus' => 'checkIn',
    ], [
        'X-HCC-Webhook-Token' => 'wrong-token',
    ])->assertForbidden();
});

test('webhook rejects requests when ingestion is disabled', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => false,
    ]);

    $this->postJson(route('webhooks.hikvision'), [
        'personName' => 'Disabled Webhook User',
        'attendanceStatus' => 'checkIn',
        'occurTime' => now()->toIso8601String(),
    ], [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertForbidden();

    Queue::assertNothingPushed();
});

test('webhook dispatches job when signed post is valid', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
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

    $this->postJson(route('webhooks.hikvision'), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});

test('webhook accepts signed post with millisecond timestamp', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
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

    $this->postJson(route('webhooks.hikvision'), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});

test('webhook stores hik-connect list envelope access event', function () {
    HikvisionSetting::current()->update([
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

    $this->postJson(route('webhooks.hikvision'), $payload, [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
        'X-Hook-Signature' => $signature,
    ])->assertNoContent();

    (new ProcessHikvisionWebhookEventJob($payload))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK)
        ->and($event->person_name)->toBe('maysa')
        ->and($event->person_hikvision_id)->toBe('549648292066532352')
        ->and($event->device_name)->toBe('OMS-Door')
        ->and($event->attendance_status)->toBe(HikvisionAccessEvent::ATTENDANCE_CHECK_IN)
        ->and($event->batch_id)->toBe($batchId)
        ->and($event->msg_type)->toBe('webhook/event/110013');

    HikvisionSetting::current()->refresh();
    expect(HikvisionSetting::current()->webhook_last_event_at)->not->toBeNull();
});

test('webhook resolves person name from synced hikvision person when only person id is sent', function () {
    HikvisionPerson::query()->create([
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

    (new ProcessHikvisionWebhookEventJob($payload))->handle();

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

    (new ProcessHikvisionWebhookEventJob($payload))->handle();

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

    (new ProcessHikvisionWebhookEventJob($payload))->handle();

    expect(HikvisionAccessEvent::query()->count())->toBe(0);
});

test('webhook dispatches job and stores event with valid token', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
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

    $this->postJson(route('webhooks.hikvision'), $payload, [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class, function (ProcessHikvisionWebhookEventJob $job) use ($payload): bool {
        return $job->payload === $payload;
    });

    (new ProcessHikvisionWebhookEventJob($payload))->handle();

    $event = HikvisionAccessEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_source)->toBe(HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK)
        ->and($event->person_name)->toBe('Webhook User');

    HikvisionSetting::current()->refresh();
    expect(HikvisionSetting::current()->webhook_last_event_at)->not->toBeNull();
});

test('webhook ignores empty payloads without updating last event timestamp', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'expected-token',
        'webhook_enabled' => true,
        'webhook_last_event_at' => null,
    ]);

    $this->postJson(route('webhooks.hikvision'), [], [
        'X-HCC-Webhook-Token' => 'expected-token',
    ])->assertNoContent();

    Queue::assertNothingPushed();
    expect(HikvisionSetting::current()->fresh()->webhook_last_event_at)->toBeNull();
});
