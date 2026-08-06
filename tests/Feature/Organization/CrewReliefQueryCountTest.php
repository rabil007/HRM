<?php

use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CurrentCrewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps current crew index query count bounded when attaching relief readiness', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);

    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    for ($i = 0; $i < 8; $i++) {
        $employee = $i === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]);

        makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            makeCrewMovementVessel("Relief QC Vessel {$i}"),
            [
                'tour_of_duty_days' => 90,
                'tour_of_duty_source' => CrewTourOfDutySource::GlobalRankDefault->value,
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => $today->addDays(12 + $i)->toDateTimeString(),
            ],
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $paginator = CurrentCrewQuery::paginate((int) $fixtures['company']->id, []);
    $items = collect($paginator->items())
        ->map(fn ($assignment) => CrewAssignmentPresenter::listItem($assignment))
        ->all();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($paginator->total())->toBe(8)
        ->and($items)->toHaveCount(8)
        ->and($items[0])->toHaveKey('relief_status')
        ->and($queryCount)->toBeLessThan(40);
});
