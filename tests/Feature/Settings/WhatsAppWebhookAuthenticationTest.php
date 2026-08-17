<?php

use App\Models\WhatsAppSetting;
use App\Support\WhatsApp\WhatsAppWebhookSignature;

beforeEach(function () {
    $settings = WhatsAppSetting::current();
    $settings->app_secret = 'meta-app-secret';
    $settings->save();
});

test('whatsapp webhook rejects unsigned post requests', function () {
    $this->postJson('/webhooks/whatsapp', [
        'entry' => [],
    ])->assertNotFound();
});

test('whatsapp webhook rejects invalid post signatures', function () {
    $this->withHeader('X-Hub-Signature-256', 'sha256=invalid')
        ->postJson('/webhooks/whatsapp', [
            'entry' => [],
        ])
        ->assertNotFound();
});

test('whatsapp webhook accepts valid post signatures', function () {
    $payload = json_encode(['entry' => []], JSON_THROW_ON_ERROR);
    $signature = WhatsAppWebhookSignature::generate('meta-app-secret', $payload);

    $this->call(
        'POST',
        '/webhooks/whatsapp',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ],
        content: $payload,
    )->assertNoContent();
});

test('legacy whatsapp webhook alias also requires a valid signature', function () {
    $this->postJson('/whatsapp/webhook', [
        'entry' => [],
    ])->assertNotFound();
});
