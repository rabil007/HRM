<?php

use App\Support\CrewAccommodation\CrewAccommodationService;
use Carbon\CarbonImmutable;

test('inclusive hotel days follow the crew information workbook formula', function () {
    $timezone = 'Asia/Dubai';
    $today = CarbonImmutable::parse('2026-10-20', $timezone);

    expect(CrewAccommodationService::calculateStayDays(
        CarbonImmutable::parse('2026-10-04', $timezone),
        CarbonImmutable::parse('2026-10-07', $timezone),
        $timezone,
        $today,
    ))->toBe(4)
        ->and(CrewAccommodationService::calculateStayDays(
            CarbonImmutable::parse('2026-10-04', $timezone),
            CarbonImmutable::parse('2026-10-04', $timezone),
            $timezone,
            $today,
        ))->toBe(1)
        ->and(CrewAccommodationService::calculateStayDays(
            CarbonImmutable::parse('2026-10-01', $timezone),
            CarbonImmutable::parse('2026-10-31', $timezone),
            $timezone,
            $today,
        ))->toBe(20);
});

test('recorded future checkout counts occupancy through today and booked inclusive stay separately', function () {
    $timezone = 'Asia/Dubai';
    $today = CarbonImmutable::parse('2026-10-13', $timezone);

    $summary = CrewAccommodationService::summarizeStayDays(
        CarbonImmutable::parse('2026-10-04', $timezone),
        CarbonImmutable::parse('2026-10-20', $timezone),
        $timezone,
        $today,
    );

    expect($summary['days'])->toBe(10)
        ->and($summary['booked_days'])->toBe(17)
        ->and($summary['has_future_checkout'])->toBeTrue()
        ->and($summary['label'])->toBe('10 of 17 hotel days');
});

test('hotel stay day boundaries respect company-local timezone', function () {
    $timezone = 'Asia/Dubai';
    // Still 13 Oct in Dubai while UTC wall clock is earlier on the calendar.
    $today = CarbonImmutable::parse('2026-10-13 19:30:00', 'UTC');

    $summary = CrewAccommodationService::summarizeStayDays(
        CarbonImmutable::parse('2026-10-04', $timezone),
        CarbonImmutable::parse('2026-10-20', $timezone),
        $timezone,
        $today,
    );

    expect($summary['days'])->toBe(10)
        ->and($summary['booked_days'])->toBe(17);
});

test('same-day and half-day hotel stays count as one full hotel day', function () {
    $summary = CrewAccommodationService::summarizeStayDays(
        CarbonImmutable::parse('2026-10-04', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-10-04', 'Asia/Dubai'),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
    );

    expect($summary['days'])->toBe(1)
        ->and($summary['label'])->toBe('1 hotel day')
        ->and($summary['invalid'])->toBeFalse();
});

test('open hotel stays count inclusive check-in through company-local today', function () {
    expect(CrewAccommodationService::calculateStayDays(
        CarbonImmutable::parse('2026-10-04', 'Asia/Dubai'),
        null,
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-07', 'Asia/Dubai'),
    ))->toBe(4);
});

test('future hotel check-in is upcoming and not counted as actual occupancy', function () {
    $summary = CrewAccommodationService::summarizeStayDays(
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-10-24', 'Asia/Dubai'),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-13', 'Asia/Dubai'),
    );

    expect($summary['days'])->toBe(0)
        ->and($summary['upcoming'])->toBeTrue()
        ->and($summary['label'])->toBe('Upcoming');
});

test('reversed hotel dates are flagged and never converted to zero', function () {
    $summary = CrewAccommodationService::summarizeStayDays(
        CarbonImmutable::parse('2026-10-07', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-10-04', 'Asia/Dubai'),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
    );

    expect($summary['days'])->toBeNull()
        ->and($summary['invalid'])->toBeTrue()
        ->and($summary['label'])->toBe('Invalid dates');
});
