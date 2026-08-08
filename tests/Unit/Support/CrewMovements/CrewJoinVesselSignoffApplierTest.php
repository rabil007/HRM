<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Support\CrewMovements\CrewJoinVesselSignoffApplier;
use App\Support\CrewMovements\CrewTourOfDutyResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function makeTourResult(?int $days = 90, string $timezone = 'Asia/Dubai'): CrewTourOfDutyResult
{
    $suggested = $days !== null
        ? CarbonImmutable::parse('2026-11-10', $timezone)->startOfDay()
        : null;

    return new CrewTourOfDutyResult(
        tourOfDutyDays: $days,
        suggestedPlannedSignoffAt: $suggested,
        timezone: $timezone,
    );
}

it('requires planned_signoff_at when explicit manual_override is chosen', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    expect(fn () => $applier->apply(
        makeTourResult(),
        [
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_override_reason' => 'Client request',
        ],
        null,
        $joinAt,
    ))->toThrow(ValidationException::class);
});

it('requires override reason when explicit manual_override includes a date', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    expect(fn () => $applier->apply(
        makeTourResult(),
        [
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2026-10-01',
        ],
        null,
        $joinAt,
    ))->toThrow(ValidationException::class);
});

it('stores explicit manual override with date and reason', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    $result = $applier->apply(
        makeTourResult(),
        [
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2026-10-01',
            'planned_signoff_override_reason' => 'Client request',
        ],
        null,
        $joinAt,
    );

    expect($result['planned_signoff_source'])->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($result['planned_signoff_at']?->toDateString())->toBe('2026-10-01')
        ->and($result['planned_signoff_override_reason'])->toBe('Client request');
});

it('requires override reason when legacy omitted choice payload includes a date', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    expect(fn () => $applier->apply(
        makeTourResult(),
        [
            'planned_signoff_at' => '2026-10-01',
        ],
        null,
        $joinAt,
    ))->toThrow(ValidationException::class);
});

it('allows legacy omitted choice payload with a date and reason', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    $result = $applier->apply(
        makeTourResult(),
        [
            'planned_signoff_at' => '2026-10-01',
            'planned_signoff_override_reason' => 'Legacy reason',
        ],
        null,
        $joinAt,
    );

    expect($result['planned_signoff_source'])->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($result['planned_signoff_at']?->toDateString())->toBe('2026-10-01')
        ->and($result['planned_signoff_override_reason'])->toBe('Legacy reason');
});

it('allows join without sign-off when no tour and no explicit manual choice', function () {
    $applier = new CrewJoinVesselSignoffApplier;
    $joinAt = CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Dubai');

    $result = $applier->apply(
        makeTourResult(null),
        [],
        null,
        $joinAt,
    );

    expect($result['planned_signoff_at'])->toBeNull()
        ->and($result['planned_signoff_source'])->toBeNull()
        ->and($result['tour_of_duty_days'])->toBeNull();
});
