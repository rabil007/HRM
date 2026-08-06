<?php

use App\Enums\CrewTourStatus;
use App\Support\CrewMovements\CrewTourOfDutyCalculator;
use Carbon\CarbonImmutable;

it('calculates suggested planned sign-off from join date plus tour days', function () {
    $calculator = new CrewTourOfDutyCalculator;
    $join = CarbonImmutable::parse('2026-08-12 22:30:00', 'Asia/Dubai');

    $suggested = $calculator->suggestedPlannedSignoff($join, 90, 'Asia/Dubai');

    expect($suggested->toDateString())->toBe('2026-11-10');
});

it('handles leap year date boundaries', function () {
    $calculator = new CrewTourOfDutyCalculator;
    $join = CarbonImmutable::parse('2024-02-28 08:00:00', 'UTC');

    $suggested = $calculator->suggestedPlannedSignoff($join, 1, 'UTC');

    expect($suggested->toDateString())->toBe('2024-02-29');
});

it('computes days onboard, current duty day, and negative remaining days', function () {
    $calculator = new CrewTourOfDutyCalculator;
    $timezone = 'Asia/Dubai';
    $join = CarbonImmutable::parse('2026-08-01 10:00:00', $timezone);
    $asOf = CarbonImmutable::parse('2026-08-11 10:00:00', $timezone);
    $signoff = CarbonImmutable::parse('2026-08-05 00:00:00', $timezone);

    $daysOnboard = $calculator->daysOnboard($join, $timezone, $asOf);

    expect($daysOnboard)->toBe(10)
        ->and($calculator->currentDutyDay($daysOnboard))->toBe(11)
        ->and($calculator->remainingTourDays($signoff, $timezone, $asOf))->toBe(-6)
        ->and($calculator->resolveStatus(90, $signoff, $timezone, $asOf))->toBe(CrewTourStatus::Overdue);
});

it('classifies due buckets correctly', function () {
    $calculator = new CrewTourOfDutyCalculator;
    $timezone = 'UTC';
    $today = CarbonImmutable::parse('2026-08-06', $timezone);

    expect($calculator->resolveStatus(90, $today, $timezone, $today))->toBe(CrewTourStatus::DueToday)
        ->and($calculator->resolveStatus(90, $today->addDays(5), $timezone, $today))->toBe(CrewTourStatus::DueWithin7Days)
        ->and($calculator->resolveStatus(90, $today->addDays(10), $timezone, $today))->toBe(CrewTourStatus::DueWithin14Days)
        ->and($calculator->resolveStatus(90, $today->addDays(20), $timezone, $today))->toBe(CrewTourStatus::DueWithin30Days)
        ->and($calculator->resolveStatus(90, $today->addDays(40), $timezone, $today))->toBe(CrewTourStatus::Normal)
        ->and($calculator->resolveStatus(null, null, $timezone, $today))->toBe(CrewTourStatus::MissingTourRule)
        ->and($calculator->resolveStatus(90, null, $timezone, $today))->toBe(CrewTourStatus::MissingSignoff);
});
