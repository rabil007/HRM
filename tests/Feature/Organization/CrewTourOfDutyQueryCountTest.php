<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use Illuminate\Support\Facades\DB;

it('keeps presenter query counts bounded for multiple assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    $ranks = collect(range(1, 8))->map(function (int $index): Rank {
        return Rank::query()->create([
            'name' => "Query Count Rank {$index} ".uniqid(),
            'is_active' => true,
            'max_tour_of_duty_days' => 60 + $index,
        ]);
    });

    $assignments = $ranks->take(5)->values()->map(function (Rank $rank, int $index) use ($fixtures) {
        $employee = $index === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $rank->id,
                'status' => 'active',
            ]);

        $assignment = makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $rank,
            makeCrewMovementVessel("Query Count Vessel {$index}"),
            [
                'tour_of_duty_days' => 90,
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => now()->addDays(20)->toDateTimeString(),
            ],
        );

        return $assignment->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'companyVisaType']);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach ($assignments as $assignment) {
        // Simulate Current Crew batching: relief + warnings attached once per page.
        $assignment->relief_readiness = CrewReliefReadinessResult::none();
        $assignment->attention_warnings = [];
        CrewAssignmentPresenter::listItem($assignment);
    }
    $presenterQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Preloaded company/phases/relief should avoid per-assignment lookups.
    expect($presenterQueries)->toBeLessThanOrEqual(2);
});
