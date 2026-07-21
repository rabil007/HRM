<?php

use App\Support\Payroll\CrewTimeline\CrewPhaseIntervalOverlapDetector;
use Carbon\CarbonImmutable;

function overlapDetect(string $ls, string $le, string $rs, string $re, string $tz = 'Asia/Dubai'): bool
{
    return (new CrewPhaseIntervalOverlapDetector)->overlaps(
        CarbonImmutable::parse($ls, $tz),
        CarbonImmutable::parse($le, $tz),
        CarbonImmutable::parse($rs, $tz),
        CarbonImmutable::parse($re, $tz),
    );
}

test('exact boundary handoff is not an overlap', function () {
    expect(overlapDetect('2026-07-10 08:00', '2026-07-15 10:00', '2026-07-15 10:00', '2026-07-20 10:00'))->toBeFalse();
});

test('positive duration intersection is an overlap', function () {
    expect(overlapDetect('2026-07-10 08:00', '2026-07-15 14:00', '2026-07-15 10:00', '2026-07-20 10:00'))->toBeTrue();
});

test('disjoint intervals on the same day are not an overlap', function () {
    expect(overlapDetect('2026-07-15 08:00', '2026-07-15 10:00', '2026-07-15 14:00', '2026-07-15 18:00'))->toBeFalse();
});

test('zero duration phase touching neighbours is not an overlap', function () {
    expect(overlapDetect('2026-07-10 08:00', '2026-07-15 10:00', '2026-07-15 10:00', '2026-07-15 10:00'))->toBeFalse();
});

test('equal instant boundary across timezones is not an overlap', function () {
    $detector = new CrewPhaseIntervalOverlapDetector;

    $left = $detector->overlaps(
        CarbonImmutable::parse('2026-07-10 08:00', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-07-15 00:00', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-07-14 20:00', 'UTC'),
        CarbonImmutable::parse('2026-07-18 10:00', 'Asia/Dubai'),
    );

    expect($left)->toBeFalse();
});

test('overlap is detected across timezones by absolute instant', function () {
    $detector = new CrewPhaseIntervalOverlapDetector;

    $result = $detector->overlaps(
        CarbonImmutable::parse('2026-07-10 08:00', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-07-15 04:00', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-07-14 20:00', 'UTC'),
        CarbonImmutable::parse('2026-07-18 10:00', 'Asia/Dubai'),
    );

    expect($result)->toBeTrue();
});
