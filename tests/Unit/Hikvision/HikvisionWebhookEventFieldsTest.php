<?php

use App\Support\Hikvision\HikvisionWebhookEventFields;

test('webhook fields map production open door payload', function () {
    $fields = HikvisionWebhookEventFields::resolve(
        [
            'elementName' => 'OMS-Door',
            'deviceName' => 'OMS-Door',
            'channelNo' => 0,
            'serialNo' => 99552,
            'cardReaderId' => '065ffb4bb3ed4290b29a467d08d5433a',
        ],
        [
            'fullPath' => 'IT',
            'personPicUrl' => 'https://example.com/face.jpg',
            'authResult' => 1,
            'attendanceStatus' => 1,
        ],
        'OMS-Door',
    );

    expect($fields['door_no'])->toBe('1')
        ->and($fields['resource_name'])->toBe('Door 1')
        ->and($fields['card_reader_no'])->toBe('1')
        ->and($fields['verify_mode'])->toBe('face')
        ->and($fields['snap_urls'])->toBe(['https://example.com/face.jpg']);
});

test('webhook fields ignore event serial number for card reader', function () {
    $fields = HikvisionWebhookEventFields::resolve(
        [
            'elementName' => 'OMS-Door',
            'serialNo' => 99591,
        ],
        [
            'fullPath' => 'Finance',
            'authResult' => 1,
        ],
        'OMS-Door',
    );

    expect($fields['card_reader_no'])->toBeNull()
        ->and($fields['door_no'])->toBeNull()
        ->and($fields['resource_name'])->toBeNull();
});

test('webhook fields prefer explicit channel and card reader numbers', function () {
    $fields = HikvisionWebhookEventFields::resolve(
        [
            'channelNo' => 1,
            'cardReaderNo' => 1,
            'elementName' => 'OMS-Door',
            'cardReaderId' => '065ffb4bb3ed4290b29a467d08d5433a',
        ],
        [
            'fullPath' => 'IT',
            'currentVerifyMode' => 'faceOrFpOrCardOrPw',
        ],
        'OMS-Door',
    );

    expect($fields['door_no'])->toBe('1')
        ->and($fields['card_reader_no'])->toBe('1')
        ->and($fields['resource_name'])->toBe('Door 1')
        ->and($fields['verify_mode'])->toBe('faceOrFpOrCardOrPw');
});

test('webhook fields resolve serial number from event basic info', function () {
    expect(HikvisionWebhookEventFields::resolveSerialNo(['serialNo' => 99611]))->toBe('99611')
        ->and(HikvisionWebhookEventFields::resolveSerialNo([]))->toBeNull();
});
