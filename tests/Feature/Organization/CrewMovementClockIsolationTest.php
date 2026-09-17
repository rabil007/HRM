<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('default organization tests run with the normal system clock', function () {
    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull();
});

test('crew movement clock helper freezes time to deterministic company time and restores cleanly', function () {
    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull();

    freezeCrewMovementTestClock();

    expect(Carbon::getTestNow())->not->toBeNull()
        ->and(Carbon::now('Asia/Dubai')->toDateTimeString())->toBe('2027-01-15 12:00:00')
        ->and(CarbonImmutable::now('Asia/Dubai')->toDateTimeString())->toBe('2027-01-15 12:00:00');

    restoreCrewMovementTestClock();

    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull();
});

test('test clock does not leak to subsequent tests', function () {
    expect(Carbon::getTestNow())->toBeNull()
        ->and(CarbonImmutable::getTestNow())->toBeNull();
});
