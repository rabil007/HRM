<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Models\Employee;
use App\Models\Position;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use Illuminate\Support\Facades\DB;

it('keeps presenter query counts bounded for multiple assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    $ranks = collect(range(1, 8))->map(function (int $index) use ($fixtures): Position {
        $rank = Position::query()->create([
            'company_id' => $fixtures['company']->id,
            'title' => "Query Count Rank {$index} ".uniqid(),
            'status' => 'active', 'is_crew_position' => true,
            'max_tour_of_duty_days' => 60 + $index,
        ]);
        ensureRankMappedPosition($fixtures['company'], $rank, 60 + $index);

        return $rank;
    });

    $assignments = $ranks->take(5)->values()->map(function (Position $rank, int $index) use ($fixtures) {
        $positionId = (int) $rank->id;
        $employee = $index === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'position_id' => $rank->id,
                'position_id' => $positionId,
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

        return $assignment->load(['employee', 'position', 'vessel', 'client', 'currentPhase', 'phases', 'company']);
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

    // Preloaded company/phases/relief/position should avoid per-assignment lookups.
    expect($presenterQueries)->toBeLessThanOrEqual(8);
});
