<?php

use App\Support\Hikvision\HikvisionWebhookSignature;

test('webhook signature matches hik-connect algorithm', function () {
    $signature = HikvisionWebhookSignature::generate('testSecret12', '1710000000', 'batch-abc');

    expect($signature)->toStartWith('sha256=')
        ->and(HikvisionWebhookSignature::verify('testSecret12', '1710000000', 'batch-abc', $signature))->toBeTrue()
        ->and(HikvisionWebhookSignature::verify('wrongSecret1', '1710000000', 'batch-abc', $signature))->toBeFalse();
});

test('webhook timestamp freshness allows one minute skew', function () {
    $now = (string) time();

    expect(HikvisionWebhookSignature::timestampIsFresh($now))->toBeTrue()
        ->and(HikvisionWebhookSignature::timestampIsFresh((string) (time() - 59)))->toBeTrue()
        ->and(HikvisionWebhookSignature::timestampIsFresh((string) (time() - 120)))->toBeFalse();
});
