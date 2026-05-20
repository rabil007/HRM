<?php

use App\Support\Employees\SeaServiceDuration;

test('sea service duration calculates multi year span', function () {
    $result = SeaServiceDuration::fromDates('2024-09-10', '2026-09-10');

    expect($result['months'])->toBe(24)
        ->and($result['days'])->toBe(731);
});

test('sea service duration calculates same day as one inclusive day', function () {
    $result = SeaServiceDuration::fromDates('2024-01-15', '2024-01-15');

    expect($result)->toBe(['months' => 0, 'days' => 1]);
});

test('sea service duration calculates inclusive days across months', function () {
    $result = SeaServiceDuration::fromDates('2024-01-15', '2024-03-14');

    expect($result['months'])->toBe(1)
        ->and($result['days'])->toBe(60);
});

test('sea service duration calculates february span as thirty inclusive days', function () {
    $result = SeaServiceDuration::fromDates('2026-02-01', '2026-03-02');

    expect($result['months'])->toBe(1)
        ->and($result['days'])->toBe(30);
});

test('sea service duration rejects end before start', function () {
    SeaServiceDuration::fromDates('2024-03-01', '2024-01-01');
})->throws(InvalidArgumentException::class);
