<?php

use App\Support\CrewMovements\CrewTourOfDutyResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->company = $this->fixtures['company'];
    $this->position = $this->fixtures['position'];
    $this->resolver = new CrewTourOfDutyResolver;
});

it('resolves Tour of Duty from assignment Position master data', function () {
    $this->position->update(['max_tour_of_duty_days' => 90]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->position->id,
        $join,
    );

    expect($result->tourOfDutyDays)->toBe(90)
        ->and($result->suggestedPlannedSignoffAt?->timezone($this->company->timezone)->toDateString())
        ->toBe('2026-11-10');
});

it('returns no automatic tour when position max_tour_of_duty_days is null or zero', function () {
    $this->position->update(['max_tour_of_duty_days' => null]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->position->id,
        $join,
    );

    expect($result->hasTour())->toBeFalse()
        ->and($result->tourOfDutyDays)->toBeNull()
        ->and($result->suggestedPlannedSignoffAt)->toBeNull();
});
