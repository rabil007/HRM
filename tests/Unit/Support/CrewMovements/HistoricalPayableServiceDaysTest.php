<?php

use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\CrewMovements\Historical\HistoricalPhaseBuilder;
use Carbon\CarbonImmutable;

test('historical preview keeps entered dates and allocates standby service around the vessel handoff', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));

    $built = HistoricalPhaseBuilder::build(new HistoricalCrewAssignmentData(
        companyId: 1,
        employeeId: 1,
        vesselId: 1,
        positionId: 1,
        rankId: 1,
        clientId: null,
        timezone: 'Asia/Dubai',
        signOnStandbyFrom: CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
        signOnStandbyTo: CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        onsiteFrom: CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        onsiteTo: CarbonImmutable::parse('2026-10-10 08:00:00', 'Asia/Dubai'),
        signOffStandbyFrom: CarbonImmutable::parse('2026-10-10 08:00:00', 'Asia/Dubai'),
        signOffStandbyTo: CarbonImmutable::parse('2026-10-14 12:00:00', 'Asia/Dubai'),
        homeAvailableFrom: CarbonImmutable::parse('2026-10-14 12:00:00', 'Asia/Dubai'),
    ));

    $periods = collect($built['known_periods'])->keyBy('key');

    expect($built['phases'][0]['actual_start_at']->toDateString())->toBe('2026-10-01')
        ->and($built['phases'][0]['actual_end_at']->toDateString())->toBe('2026-10-05')
        ->and($periods['sign_on_standby']['days'])->toBe(5)
        ->and($periods['sign_on_standby']['allocated_service_days'])->toBe(4)
        ->and($periods['sign_on_standby']['service_days_label'])->toBe('Standby service: 4 days')
        ->and($periods['onsite']['allocated_service_days'])->toBe(6)
        ->and($periods['sign_off_standby']['days'])->toBe(5)
        ->and($periods['sign_off_standby']['allocated_service_days'])->toBe(4)
        ->and($periods['sign_off_standby']['service_days_label'])->toBe('Sign-off standby: 4 days')
        ->and($periods['home_available']['allocated_service_days'])->toBeNull();

    CarbonImmutable::setTestNow();
});

test('historical standby that ends without vessel service is not shortened by one day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));

    $built = HistoricalPhaseBuilder::build(new HistoricalCrewAssignmentData(
        companyId: 1,
        employeeId: 1,
        vesselId: 1,
        positionId: 1,
        rankId: 1,
        clientId: null,
        timezone: 'Asia/Dubai',
        signOnStandbyFrom: CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
        signOnStandbyTo: CarbonImmutable::parse('2026-10-03 18:00:00', 'Asia/Dubai'),
        homeAvailableFrom: CarbonImmutable::parse('2026-10-03 18:00:00', 'Asia/Dubai'),
    ));

    $standby = collect($built['known_periods'])->firstWhere('key', 'sign_on_standby');

    expect($standby['days'])->toBe(3)
        ->and($standby['allocated_service_days'])->toBe(3)
        ->and($standby['service_days_label'])->toBe('Standby service: 3 days');

    CarbonImmutable::setTestNow();
});
