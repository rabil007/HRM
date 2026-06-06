<?php

use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use Illuminate\Support\Facades\Queue;

test('webhook rejects requests with invalid verify token', function () {
    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'expected-token',
    ]);

    $this->postJson(route('webhooks.hikvision'), [
        'personInfo' => ['personName' => 'Webhook User'],
        'occurTime' => now()->toIso8601String(),
        'attendanceStatus' => 'checkIn',
    ], [
        'X-HCC-Webhook-Token' => 'wrong-token',
    ])->assertForbidden();
});

test('webhook dispatches job and stores event with valid token', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'expected-token',
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

test('webhook accepts verify token from query string', function () {
    Queue::fake();

    HikvisionSetting::current()->update([
        'webhook_verify_token' => 'query-token',
    ]);

    $this->postJson(route('webhooks.hikvision', ['token' => 'query-token']), [
        'personName' => 'Query Token User',
        'attendanceStatus' => 'checkOut',
        'occurTime' => now()->toIso8601String(),
    ])->assertNoContent();

    Queue::assertPushed(ProcessHikvisionWebhookEventJob::class);
});
