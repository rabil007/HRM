<?php

use App\Support\Hikvision\HikvisionPersonWritePayload;
use Illuminate\Support\Carbon;

test('for update builds flat payload with iso validity dates', function () {
    $payload = HikvisionPersonWritePayload::forUpdate(
        [
            'first_name' => 'Mohammed',
            'last_name' => 'Rabil',
            'group_id' => '701420439763054592',
            'email' => 'rabil@example.com',
            'phone' => '971-569769023',
        ],
        [
            'personId' => '705076684197985280',
            'groupId' => '701420439763054592',
            'firstName' => 'Rabil',
            'lastName' => '',
            'gender' => 1,
            'personCode' => '13',
            'startDate' => 1770062400000,
            'endDate' => 2085595199000,
            'email' => 'rabil@example.com',
            'phone' => '971-569769023',
        ],
    );

    expect($payload)->toMatchArray([
        'personId' => '705076684197985280',
        'groupId' => '701420439763054592',
        'firstName' => 'Mohammed',
        'lastName' => 'Rabil',
        'gender' => 1,
        'personCode' => '13',
        'email' => 'rabil@example.com',
        'phone' => '971-569769023',
    ])
        ->and($payload)->not->toHaveKey('personInfo')
        ->and($payload['startDate'])->toBe(
            Carbon::createFromTimestampMs(1770062400000)->format('Y-m-d\TH:i:sP'),
        );
});

test('merge updated detail applies validated fields locally', function () {
    $merged = HikvisionPersonWritePayload::mergeUpdatedDetail(
        [
            'first_name' => 'Mohammed',
            'last_name' => 'Rabil',
            'group_id' => '701420439763054592',
            'email' => 'updated@example.com',
            'phone' => '971-500000000',
        ],
        [
            'personId' => '705076684197985280',
            'groupId' => '658692039869088768',
            'firstName' => 'Rabil',
            'lastName' => '',
            'email' => 'old@example.com',
            'phone' => '971-569769023',
        ],
    );

    expect($merged)->toMatchArray([
        'personId' => '705076684197985280',
        'firstName' => 'Mohammed',
        'lastName' => 'Rabil',
        'groupId' => '701420439763054592',
        'email' => 'updated@example.com',
        'phone' => '971-500000000',
    ]);
});

test('for create builds flat payload with default validity period', function () {
    $payload = HikvisionPersonWritePayload::forCreate([
        'first_name' => 'New',
        'last_name' => 'Person',
        'group_id' => '701420439763054592',
        'person_code' => 'EMP999',
    ]);

    expect($payload)->toMatchArray([
        'firstName' => 'New',
        'lastName' => 'Person',
        'gender' => 2,
        'groupId' => '701420439763054592',
        'personCode' => 'EMP999',
        'endDate' => '2037-12-31T23:59:59+00:00',
    ])->and($payload)->not->toHaveKey('personInfo');
});
