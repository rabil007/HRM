<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Illuminate\Support\Collection;

function overlapWarningExists(int $preparationId): bool
{
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('warning_code', CrewTimelineWarningCode::OverlappingPhases->value)
        ->exists();
}

/**
 * @return Collection<int, CrewTimesheetPreparationLine>
 */
function payableLinesCovering(int $preparationId, string $date)
{
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('days', '>', 0)
        ->whereDate('from_date', '<=', $date)
        ->whereDate('to_date', '>=', $date)
        ->get();
}

test('exact phase handoffs produce no overlap warning and onsite wins transition dates', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-20 10:00:00', '2026-07-22 08:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFifteen = payableLinesCovering($preparation->id, '2026-07-15');
    $julyTwenty = payableLinesCovering($preparation->id, '2026-07-20');

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($julyFifteen)->toHaveCount(1)
        ->and($julyFifteen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and($julyTwenty)->toHaveCount(1)
        ->and($julyTwenty->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('genuine timestamp overlap produces a blocking overlap warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 14:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFifteen = payableLinesCovering($preparation->id, '2026-07-15');

    expect(overlapWarningExists($preparation->id))->toBeTrue()
        ->and($julyFifteen)->toHaveCount(1)
        ->and($julyFifteen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('exact join standby to training handoff keeps sign-on standby without overlap warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-05 08:00:00', '2026-07-08 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::Training, 2, '2026-07-08 10:00:00', '2026-07-10 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyEight = payableLinesCovering($preparation->id, '2026-07-08');

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($julyEight)->toHaveCount(1)
        ->and($julyEight->first()->pay_category)->toBe(CrewTimesheetPayCategory::SignOnStandby);
});

test('repeated join standby around training produces no false overlap and no duplicate days', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::Training, 2, '2026-07-03 10:00:00', '2026-07-05 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 3, '2026-07-05 10:00:00', '2026-07-07 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payable = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->get();

    $totalDays = (float) $payable->sum('days');
    $distinctPhaseLines = $payable
        ->pluck('crew_assignment_phase_id')
        ->unique()
        ->count();

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($totalDays)->toBe(7.0)
        ->and($distinctPhaseLines)->toBe(3);
});

test('same day disjoint timestamps produce no overlap warning and pick one winner', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-15 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 14:00:00', '2026-07-15 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFifteen = payableLinesCovering($preparation->id, '2026-07-15');

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($julyFifteen)->toHaveCount(1)
        ->and($julyFifteen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('exact handoff at local midnight produces no overlap and correct dates', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 00:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 00:00:00', '2026-07-18 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFifteen = payableLinesCovering($preparation->id, '2026-07-15');

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($julyFifteen)->toHaveCount(1)
        ->and($julyFifteen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('active phase with null actual end is clipped without false overlap', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', null, CrewPhaseStatus::Active);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(overlapWarningExists($preparation->id))->toBeFalse();
});

test('invalid phase range still raises invalid_phase_range without crashing', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-15 10:00:00', '2026-07-10 08:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $invalidRangeExists = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('warning_code', CrewTimelineWarningCode::InvalidPhaseRange->value)
        ->exists();

    expect($invalidRangeExists)->toBeTrue()
        ->and(overlapWarningExists($preparation->id))->toBeFalse();
});

test('multiple genuine overlapping phases stay deterministic and blocking', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 12:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-12 08:00:00', '2026-07-19 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-14 08:00:00', '2026-07-18 08:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFourteen = payableLinesCovering($preparation->id, '2026-07-14');

    expect(overlapWarningExists($preparation->id))->toBeTrue()
        ->and($julyFourteen)->toHaveCount(1)
        ->and($julyFourteen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('preparation with only exact handoffs can be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasNoErrors();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Submitted);
});

test('preparation with genuine overlap cannot be submitted', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 14:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.submit', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Draft);
});
