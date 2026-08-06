<?php

use App\Enums\CrewTourOfDutySource;
use App\Models\CrewRankPolicy;
use App\Support\CrewMovements\CrewTourOfDutyResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->company = $this->fixtures['company'];
    $this->rank = $this->fixtures['rank'];
    $this->resolver = new CrewTourOfDutyResolver;
});

it('uses assignment override over company policy and global default', function () {
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    CrewRankPolicy::query()->create([
        'company_id' => $this->company->id,
        'rank_id' => $this->rank->id,
        'tour_of_duty_days' => 120,
        'is_active' => true,
    ]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->rank->id,
        $join,
        75,
    );

    expect($result->tourOfDutyDays)->toBe(75)
        ->and($result->tourOfDutySource)->toBe(CrewTourOfDutySource::AssignmentOverride)
        ->and($result->suggestedPlannedSignoffAt?->timezone($this->company->timezone)->toDateString())
        ->toBe('2026-10-26');
});

it('uses company rank policy over global rank default', function () {
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    CrewRankPolicy::query()->create([
        'company_id' => $this->company->id,
        'rank_id' => $this->rank->id,
        'tour_of_duty_days' => 120,
        'is_active' => true,
    ]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->rank->id,
        $join,
    );

    expect($result->tourOfDutyDays)->toBe(120)
        ->and($result->tourOfDutySource)->toBe(CrewTourOfDutySource::CompanyRankPolicy)
        ->and($result->suggestedPlannedSignoffAt?->timezone($this->company->timezone)->toDateString())
        ->toBe('2026-12-10');
});

it('falls back to global rank default when no company policy exists', function () {
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->rank->id,
        $join,
    );

    expect($result->tourOfDutyDays)->toBe(90)
        ->and($result->tourOfDutySource)->toBe(CrewTourOfDutySource::GlobalRankDefault)
        ->and($result->suggestedPlannedSignoffAt?->timezone($this->company->timezone)->toDateString())
        ->toBe('2026-11-10');
});

it('returns no automatic tour when no rule exists', function () {
    $this->rank->update(['max_tour_of_duty_days' => null]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->rank->id,
        $join,
    );

    expect($result->hasTour())->toBeFalse()
        ->and($result->tourOfDutyDays)->toBeNull()
        ->and($result->suggestedPlannedSignoffAt)->toBeNull();
});

it('never uses another company rank policy', function () {
    $other = makeCrewAssignmentFixtures();
    $this->rank->update(['max_tour_of_duty_days' => 90]);

    CrewRankPolicy::query()->create([
        'company_id' => $other['company']->id,
        'rank_id' => $this->rank->id,
        'tour_of_duty_days' => 200,
        'is_active' => true,
    ]);

    $join = CarbonImmutable::parse('2026-08-12 10:00:00', $this->company->timezone);

    $result = $this->resolver->resolve(
        (int) $this->company->id,
        (int) $this->rank->id,
        $join,
    );

    expect($result->tourOfDutyDays)->toBe(90)
        ->and($result->tourOfDutySource)->toBe(CrewTourOfDutySource::GlobalRankDefault);
});
