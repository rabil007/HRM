<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Models\CrewRankPolicy;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewTourOfDutyResolver;
use App\Support\CrewOperations\CrewRankPolicyIndexQuery;
use Illuminate\Support\Facades\DB;

it('keeps rank policy index and presenter query counts bounded for multiple ranks and assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    $ranks = collect(range(1, 8))->map(function (int $index) use ($companyId): Rank {
        $rank = Rank::query()->create([
            'name' => "Query Count Rank {$index} ".uniqid(),
            'is_active' => true,
            'max_tour_of_duty_days' => 60 + $index,
        ]);

        CrewRankPolicy::query()->create([
            'company_id' => $companyId,
            'rank_id' => $rank->id,
            'tour_of_duty_days' => 50 + $index,
            'is_active' => true,
        ]);

        return $rank;
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = CrewRankPolicyIndexQuery::forCompany($companyId);
    $indexQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->not->toBeEmpty()
        ->and($indexQueries)->toBeLessThan(8);

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
                'tour_of_duty_source' => CrewTourOfDutySource::CompanyRankPolicy->value,
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => now()->addDays(20)->toDateTimeString(),
            ],
        );

        return $assignment->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'companyVisaType']);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach ($assignments as $assignment) {
        CrewAssignmentPresenter::listItem($assignment);
    }
    $presenterQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Preloaded company/phases should avoid per-assignment timezone lookups.
    expect($presenterQueries)->toBeLessThanOrEqual(2);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolver = new CrewTourOfDutyResolver;
    $map = $resolver->companyPolicyDaysByRankId($companyId);
    foreach ($ranks as $rank) {
        $resolver->previewForRank($companyId, $rank, $map);
    }
    $previewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($previewQueries)->toBe(1)
        ->and($map)->toHaveCount(8);
});
