<?php

use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionAccessEvent;
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
