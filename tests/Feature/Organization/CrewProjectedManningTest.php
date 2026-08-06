<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewProjectedManningStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Carbon\CarbonImmutable;

function makeProjectedManningPosition(array $fixtures, string $vesselName, int $required = 1): array
{
    $vessel = makeCrewMovementVessel($vesselName);

    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => $required,
    ]);

    return [
        'company' => $fixtures['company'],
        'rank' => $fixtures['rank'],
        'vessel' => $vessel,
        'user' => $fixtures['user'],
        'employee' => $fixtures['employee'],
    ];
}

function projectManning(array $ctx, string $from, string $to, ?int $vesselId = null, ?int $rankId = null): array
{
    return (new CrewProjectedManningQuery)->forCompany(
        (int) $ctx['company']->id,
        $from,
        $to,
        $vesselId ?? (int) $ctx['vessel']->id,
        $rankId ?? (int) $ctx['rank']->id,
    );
}

function firstProjectedItem(array $result): array
{
    expect($result['items'])->not->toBeEmpty();

    return $result['items'][0];
}

it('counts current onboard from actual P4 coverage at range start', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Onboard Start Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['actual_onboard_at_start'])->toBe(1)
        ->and($item['projected_count_at_start'])->toBe(1)
        ->and($item['starting_count'])->toBe(1)
        ->and($item['required_count'])->toBe(1)
        ->and($item['current_gap'])->toBe(0)
        ->and($item['status'])->toBe(CrewProjectedManningStatus::Covered->value);
});

it('produces current gap when Vessel Manning has no crew', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Empty Manning Vessel', required: 2);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['actual_onboard_at_start'])->toBe(0)
        ->and($item['projected_count_at_start'])->toBe(0)
        ->and($item['starting_count'])->toBe(0)
        ->and($item['current_gap'])->toBe(2)
        ->and($item['status'])->toBe(CrewProjectedManningStatus::CurrentGap->value)
        ->and($item['next_gap_date'])->toBe('2026-08-01');
});

it('produces future gap from planned sign-off without replacement', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Future Gap Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['starting_count'])->toBe(1)
        ->and($item['current_gap'])->toBe(0)
        ->and($item['maximum_gap'])->toBe(1)
        ->and($item['status'])->toBe(CrewProjectedManningStatus::FutureGap->value)
        ->and($item['next_gap_date'])->toBe('2026-08-20');
});

it('prevents gap when a replacement joins before or on the same day as sign-off', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Incoming Cover Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    $relief = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $relief->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
        'relieves_crew_assignment_id' => null,
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['maximum_gap'])->toBe(0)
        ->and($item['status'])->toBe(CrewProjectedManningStatus::CoveredByIncoming->value);
});

it('creates a bounded gap for late relief and overlap for early relief', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Late Early Relief Vessel');
    $source = makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    $late = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $late->id,
        'planned_join_date' => '2026-08-22',
        'planned_leave_date' => '2026-11-22',
        'relieves_crew_assignment_id' => $source->id,
    ]);

    $lateItem = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    expect($lateItem['status'])->toBe(CrewProjectedManningStatus::FutureGap->value)
        ->and($lateItem['next_gap_date'])->toBe('2026-08-20')
        ->and(collect($lateItem['periods'])->firstWhere('gap', 1)['to'] ?? null)->toBe('2026-08-21');

    $earlyFixtures = makeCrewAssignmentFixtures();
    $earlyCtx = makeProjectedManningPosition($earlyFixtures, 'Early Relief Vessel');
    makeActiveOnVesselAssignment($earlyCtx['company'], $earlyCtx['employee'], $earlyCtx['rank'], $earlyCtx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    $early = Employee::factory()->forCompany($earlyCtx['company'])->create([
        'rank_id' => $earlyCtx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $earlyCtx['company']->id,
        'vessel_id' => $earlyCtx['vessel']->id,
        'rank_id' => $earlyCtx['rank']->id,
        'employee_id' => $early->id,
        'planned_join_date' => '2026-08-18',
        'planned_leave_date' => '2026-11-18',
    ]);

    $earlyItem = firstProjectedItem(projectManning($earlyCtx, '2026-08-01', '2026-08-31'));
    expect($earlyItem['has_overlap'])->toBeTrue()
        ->and($earlyItem['maximum_projected_count'])->toBe(2);
});

it('does not create an artificial gap for same-day join and sign-off', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Same Day Handover Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    $relief = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $relief->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    $gapPeriods = collect($item['periods'])->filter(fn (array $p): bool => $p['gap'] > 0);

    expect($gapPeriods)->toBeEmpty()
        ->and($item['maximum_gap'])->toBe(0)
        ->and($item['has_overlap'])->toBeFalse()
        ->and($item['maximum_projected_count'])->toBe(1);
});

it('ignores vacant Planning and counts Planning-only employees once with linked assignments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Vacant And Linked Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => null,
        'planned_join_date' => '2026-08-15',
        'planned_leave_date' => '2026-11-15',
    ]);

    $vacantItem = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    expect(collect($vacantItem['events'])->where('type', 'join'))->toBeEmpty();

    $planner = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $planner->id,
        'planned_join_date' => '2026-08-25',
        'planned_leave_date' => '2026-11-25',
    ]);

    $planningOnly = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    expect(collect($planningOnly['events'])->where('type', 'join'))->toHaveCount(1);

    app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $ctx['user']->id);
    $linked = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    expect(collect($linked['events'])->where('type', 'join'))->toHaveCount(1)
        ->and(collect($linked['events'])->where('type', 'join')->first()['crew_assignment_id'])->not->toBeNull();
});

it('prefers actual join and end dates over planned and planning dates', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Precedence Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'assignment_no' => 'CA-2026-PREC01',
        'employee_id' => $ctx['employee']->id,
        'rank_id' => $ctx['rank']->id,
        'vessel_id' => $ctx['vessel']->id,
        'status' => CrewAssignmentStatus::Active,
        'planned_join_at' => '2026-08-10 00:00:00',
        'planned_signoff_at' => '2026-08-28 00:00:00',
        'source' => 'manual',
    ]);
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-07-01 08:00:00',
        'actual_end_at' => '2026-08-15 18:00:00',
        'planned_end_at' => '2026-08-30 00:00:00',
    ]);
    $assignment->update(['current_phase_id' => $phase->id]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $ctx['employee']->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2026-08-12',
        'planned_leave_date' => '2026-09-01',
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));
    expect($item['starting_count'])->toBe(1)
        ->and(collect($item['events'])->firstWhere('type', 'signoff')['date'])->toBe('2026-08-15');
});

it('keeps open-ended P4 onboard through the horizon and flags unplanned sign-off', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Open Ended Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => null,
    ]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-10-31'));

    expect($item['starting_count'])->toBe(1)
        ->and($item['minimum_projected_count'])->toBe(1)
        ->and($item['has_open_ended_onboard'])->toBeTrue()
        ->and($item['events'])->toBeEmpty();
});

it('excludes cancelled assignments completed service and soft-deleted planning', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Exclusions Vessel');

    $cancelled = makeActiveOnVesselAssignment(
        $ctx['company'],
        Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ]),
        $ctx['rank'],
        $ctx['vessel'],
    );
    $cancelled->update(['status' => CrewAssignmentStatus::Cancelled]);

    $ended = CrewAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'assignment_no' => 'CA-2026-ENDED1',
        'employee_id' => Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ])->id,
        'rank_id' => $ctx['rank']->id,
        'vessel_id' => $ctx['vessel']->id,
        'status' => CrewAssignmentStatus::Completed,
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $ended->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-07-15 08:00:00',
    ]);

    $deleted = CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ])->id,
        'planned_join_date' => '2026-08-10',
        'planned_leave_date' => '2026-11-10',
    ]);
    $deleted->delete();

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['starting_count'])->toBe(0)
        ->and($item['events'])->toBeEmpty()
        ->and($item['status'])->toBe(CrewProjectedManningStatus::CurrentGap->value);
});

it('uses company timezone calendar boundaries for event dates', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $ctx = makeProjectedManningPosition($fixtures, 'Timezone Vessel');

    $assignment = makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel']);
    $assignment->update(['planned_signoff_at' => null]);
    $assignment->currentPhase->update([
        'actual_start_at' => CarbonImmutable::parse('2026-08-01 01:30:00', 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse('2026-08-21 01:00:00', 'Asia/Dubai'),
    ]);

    $result = projectManning($ctx, '2026-08-01', '2026-08-31');
    $item = firstProjectedItem($result);

    expect($result['company_timezone'])->toBe('Asia/Dubai')
        ->and($item['starting_count'])->toBe(1)
        ->and(collect($item['events'])->firstWhere('type', 'signoff')['date'])->toBe('2026-08-21');
});

it('does not treat overdue Planning or pre-P4 assignments as actual onboard but may project them', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Overdue Projected Vessel');

    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ])->id,
        'planned_join_date' => '2026-07-15',
        'planned_leave_date' => '2026-10-15',
    ]);

    $draft = CrewAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'assignment_no' => 'CA-2026-DRAFT1',
        'employee_id' => Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ])->id,
        'rank_id' => $ctx['rank']->id,
        'vessel_id' => $ctx['vessel']->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => '2026-07-10 00:00:00',
        'planned_signoff_at' => '2026-10-10 00:00:00',
        'source' => 'manual',
    ]);
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $draft->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
    ]);
    $draft->update(['current_phase_id' => $phase->id]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['actual_onboard_at_start'])->toBe(0)
        ->and($item['projected_count_at_start'])->toBe(2)
        ->and($item['starting_count'])->toBe(2);
});

it('keeps multiple actual P4 occurrences as separate segments', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Repeatable P4 Vessel', required: 1);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'assignment_no' => 'CA-2026-REP4',
        'employee_id' => $ctx['employee']->id,
        'rank_id' => $ctx['rank']->id,
        'vessel_id' => $ctx['vessel']->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-07-01 08:00:00',
        'actual_end_at' => '2026-07-20 08:00:00',
    ]);
    $second = CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-10 08:00:00',
        'actual_end_at' => null,
    ]);
    $assignment->update(['current_phase_id' => $second->id]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect($item['actual_onboard_at_start'])->toBe(0)
        ->and(collect($item['events'])->where('type', 'join')->pluck('date')->all())->toBe(['2026-08-10'])
        ->and($item['maximum_projected_count'])->toBe(1);
});

it('does not let completed or P5/P6 assignments create stale future joins', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Stale Future Vessel');

    $completed = CrewAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'assignment_no' => 'CA-2026-COMP1',
        'employee_id' => Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ])->id,
        'rank_id' => $ctx['rank']->id,
        'vessel_id' => $ctx['vessel']->id,
        'status' => CrewAssignmentStatus::Completed,
        'planned_join_at' => '2026-08-15 00:00:00',
        'planned_signoff_at' => '2026-09-15 00:00:00',
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $completed->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-07-01 08:00:00',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $completed->employee_id,
        'crew_assignment_id' => $completed->id,
        'planned_join_date' => '2026-08-18',
        'planned_leave_date' => '2026-11-18',
    ]);

    $p5 = makeActiveOnVesselAssignment(
        $ctx['company'],
        Employee::factory()->forCompany($ctx['company'])->create([
            'rank_id' => $ctx['rank']->id,
            'status' => 'active',
        ]),
        $ctx['rank'],
        $ctx['vessel'],
        ['planned_join_at' => '2026-08-25 00:00:00', 'planned_signoff_at' => '2026-09-25 00:00:00'],
    );
    $p5->currentPhase->update([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-05-01 08:00:00',
        'actual_end_at' => '2026-06-01 08:00:00',
    ]);
    $demob = CrewAssignmentPhase::query()->create([
        'company_id' => $ctx['company']->id,
        'crew_assignment_id' => $p5->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-06-01 09:00:00',
    ]);
    $p5->update(['current_phase_id' => $demob->id]);

    $item = firstProjectedItem(projectManning($ctx, '2026-08-01', '2026-08-31'));

    expect(collect($item['events'])->where('type', 'join'))->toBeEmpty()
        ->and($item['actual_onboard_at_start'])->toBe(0)
        ->and($item['projected_count_at_start'])->toBe(0);
});

it('creates overlap only for same-day net increase and counts summary overlap from has_overlap', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $ctx = makeProjectedManningPosition($fixtures, 'Same Day Net Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    $incomingA = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    $incomingB = Employee::factory()->forCompany($ctx['company'])->create([
        'rank_id' => $ctx['rank']->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $incomingA->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $ctx['company']->id,
        'vessel_id' => $ctx['vessel']->id,
        'rank_id' => $ctx['rank']->id,
        'employee_id' => $incomingB->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
    ]);

    $result = projectManning($ctx, '2026-08-01', '2026-08-31');
    $item = firstProjectedItem($result);

    expect($item['has_overlap'])->toBeTrue()
        ->and($item['maximum_projected_count'])->toBe(2)
        ->and($result['summary']['overlap_positions'])->toBe(1);
});

it('converts CarbonInterface range bounds through the company timezone', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $ctx = makeProjectedManningPosition($fixtures, 'Carbon Bound Vessel');
    makeActiveOnVesselAssignment($ctx['company'], $ctx['employee'], $ctx['rank'], $ctx['vessel'], [
        'planned_signoff_at' => null,
    ]);

    $from = CarbonImmutable::parse('2026-07-31 22:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-08-31 10:00:00', 'UTC');

    $result = (new CrewProjectedManningQuery)->forCompany(
        (int) $ctx['company']->id,
        $from,
        $to,
        (int) $ctx['vessel']->id,
        (int) $ctx['rank']->id,
    );

    expect($result['from'])->toBe('2026-08-01')
        ->and($result['to'])->toBe('2026-08-31')
        ->and($result['items'][0]['actual_onboard_at_start'])->toBe(1);
});
