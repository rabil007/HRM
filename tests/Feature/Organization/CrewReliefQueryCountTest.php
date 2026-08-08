<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewReliefStatus;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
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
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => $today->addDays(12 + $i)->toDateTimeString(),
            ],
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = CurrentCrewQuery::paginate((int) $fixtures['company']->id);
    collect($page->items())->each(fn ($a) => CrewAssignmentPresenter::listItem($a));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($page->total())->toBe(8)
        ->and($queryCount)->toBeLessThan(12);
});

it('maintains constant query count scaling as onboard crew size grows without reliefs', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    $makeOnboard = function (int $index) use ($fixtures, $today) {
        $employee = $index === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]);

        return makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            makeCrewMovementVessel("No Relief Scale {$index}"),
            [
                'tour_of_duty_days' => 90,
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => $today->addDays(20)->toDateTimeString(),
            ],
        );
    };

    $makeOnboard(0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $one = CurrentCrewQuery::paginate($companyId, ['per_page' => 50]);
    collect($one->items())->each(fn ($a) => CrewAssignmentPresenter::listItem($a));
    $oneCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($one->total())->toBe(1)
        ->and($one->items()[0]->relief_readiness->status)->toBe(CrewReliefStatus::NoRelief);

    for ($i = 1; $i < 20; $i++) {
        $makeOnboard($i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $many = CurrentCrewQuery::paginate($companyId, ['per_page' => 50]);
    collect($many->items())->each(fn ($a) => CrewAssignmentPresenter::listItem($a));
    $manyCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many->total())->toBe(20)
        ->and($manyCount)->toBeLessThanOrEqual($oneCount + 8)
        ->and($manyCount - $oneCount)->toBeLessThan(20);
});

it('keeps Current Crew queries bounded for mixed relief states as row count grows', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();
    $resolver = new CrewReliefReadinessResolver;

    $seedMixedBatch = function (int $batch) use ($fixtures, $today, $resolver): void {
        $base = $batch * 5;

        // no relief
        makeActiveOnVesselAssignment(
            $fixtures['company'],
            Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]),
            $fixtures['rank'],
            makeCrewMovementVessel("Mixed None {$base}"),
            ['planned_signoff_at' => $today->addDays(18)->toDateTimeString()],
        );

        // planning-only
        $sourcePlanned = makeActiveOnVesselAssignment(
            $fixtures['company'],
            Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]),
            $fixtures['rank'],
            makeCrewMovementVessel("Mixed Planned {$base}"),
            ['planned_signoff_at' => $today->addDays(12)->toDateTimeString()],
        );
        CrewPlanningAssignment::query()->create([
            'company_id' => $fixtures['company']->id,
            'vessel_id' => $sourcePlanned->vessel_id,
            'rank_id' => $sourcePlanned->rank_id,
            'employee_id' => Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ])->id,
            'relieves_crew_assignment_id' => $sourcePlanned->id,
            'planned_join_date' => $today->addDays(12)->toDateString(),
            'planned_leave_date' => $today->addDays(100)->toDateString(),
        ]);

        // linked draft
        $sourceDraft = makeActiveOnVesselAssignment(
            $fixtures['company'],
            Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]),
            $fixtures['rank'],
            makeCrewMovementVessel("Mixed Draft {$base}"),
            ['planned_signoff_at' => $today->addDays(11)->toDateTimeString()],
        );
        $draftPlan = CrewPlanningAssignment::query()->create([
            'company_id' => $fixtures['company']->id,
            'vessel_id' => $sourceDraft->vessel_id,
            'rank_id' => $sourceDraft->rank_id,
            'employee_id' => Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ])->id,
            'relieves_crew_assignment_id' => $sourceDraft->id,
            'planned_join_date' => $today->addDays(11)->toDateString(),
            'planned_leave_date' => $today->addDays(100)->toDateString(),
        ]);
        app(CreateCrewAssignmentFromPlanning::class)->handle($draftPlan, $fixtures['user']->id);

        // linked P3
        $sourceP3 = makeActiveOnVesselAssignment(
            $fixtures['company'],
            Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]),
            $fixtures['rank'],
            makeCrewMovementVessel("Mixed P3 {$base}"),
            ['planned_signoff_at' => $today->addDays(9)->toDateTimeString()],
        );
        $p3Plan = CrewPlanningAssignment::query()->create([
            'company_id' => $fixtures['company']->id,
            'vessel_id' => $sourceP3->vessel_id,
            'rank_id' => $sourceP3->rank_id,
            'employee_id' => Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ])->id,
            'relieves_crew_assignment_id' => $sourceP3->id,
            'planned_join_date' => $today->addDays(9)->toDateString(),
            'planned_leave_date' => $today->addDays(100)->toDateString(),
        ]);
        $p3Linked = app(CreateCrewAssignmentFromPlanning::class)->handle($p3Plan, $fixtures['user']->id);
        $p3Linked->update(['status' => CrewAssignmentStatus::Active]);
        $p3Linked->currentPhase->update([
            'phase_code' => CrewPhaseCode::ReadyToJoin,
            'status' => CrewPhaseStatus::Active,
        ]);

        // linked P4
        $sourceP4 = makeActiveOnVesselAssignment(
            $fixtures['company'],
            Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]),
            $fixtures['rank'],
            makeCrewMovementVessel("Mixed P4 {$base}"),
            ['planned_signoff_at' => $today->addDays(8)->toDateTimeString()],
        );
        $p4Plan = CrewPlanningAssignment::query()->create([
            'company_id' => $fixtures['company']->id,
            'vessel_id' => $sourceP4->vessel_id,
            'rank_id' => $sourceP4->rank_id,
            'employee_id' => Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ])->id,
            'relieves_crew_assignment_id' => $sourceP4->id,
            'planned_join_date' => $today->addDays(8)->toDateString(),
            'planned_leave_date' => $today->addDays(100)->toDateString(),
        ]);
        $p4Linked = app(CreateCrewAssignmentFromPlanning::class)->handle($p4Plan, $fixtures['user']->id);
        $p4Linked->update(['status' => CrewAssignmentStatus::Active]);
        $p4Linked->currentPhase->update([
            'phase_code' => CrewPhaseCode::OnVessel,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => now(),
        ]);

        expect($resolver->forSourceAssignment($sourceP3->fresh())->status)->toBe(CrewReliefStatus::ReadyToJoin)
            ->and($resolver->forSourceAssignment($sourceP4->fresh())->status)->toBe(CrewReliefStatus::ReliefOnboard);
    };

    $seedMixedBatch(0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = CurrentCrewQuery::paginate($companyId, ['per_page' => 100]);
    collect($small->items())->each(fn ($a) => CrewAssignmentPresenter::listItem($a));
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $seedMixedBatch(1);
    $seedMixedBatch(2);
    $seedMixedBatch(3);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $large = CurrentCrewQuery::paginate($companyId, ['per_page' => 100]);
    collect($large->items())->each(fn ($a) => CrewAssignmentPresenter::listItem($a));
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 4 batches × 5 source assignments = 20 onboard sources (+ linked drafts are separate assignments)
    expect($small->total())->toBeGreaterThanOrEqual(5)
        ->and($large->total())->toBeGreaterThan($small->total())
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 12)
        ->and($largeCount - $smallCount)->toBeLessThan($large->total() - $small->total());
});

it('forPreloadedPlan with null does not query for a relief plan', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Preload Null Vessel'),
        ['planned_signoff_at' => now()->addDays(10)->toDateTimeString()],
    )->fresh(['company']);

    $resolver = new CrewReliefReadinessResolver;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $resolver->forPreloadedPlan($source, null);
    $queries = collect(DB::getQueryLog())->filter(
        fn (array $q): bool => str_contains($q['query'], 'crew_planning_assignments'),
    );
    DB::disableQueryLog();

    expect($result->status)->toBe(CrewReliefStatus::NoRelief)
        ->and($queries)->toHaveCount(0);
});
