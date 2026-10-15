<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionPresenter;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewResource;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;

function legacyPayrollReviewPayload(array $fixtures, int $preparationId): array
{
    $loaded = app(CrewTimesheetPreparationReviewQuery::class)->findForReview(
        $fixtures['period'],
        $preparationId,
        (int) $fixtures['company']->id,
    );

    return app(CrewTimesheetPreparationReviewResource::class)->toArray(
        $fixtures['period'],
        $loaded,
    );
}

/**
 * @return list<string>
 */
function reviewPhaseCodes(array $payload, int $employeeId): array
{
    $employee = collect($payload['employees'])->firstWhere('employee_id', $employeeId);

    return collect($employee['assignments'] ?? [])
        ->flatMap(fn (array $assignment): array => collect($assignment['phases'] ?? [])
            ->pluck('phase_code')
            ->filter()
            ->all())
        ->values()
        ->all();
}

function assertEachOperationalDayHasSinglePayableLine(int $preparationId, string $from, string $to): void
{
    $current = CarbonImmutable::parse($from);
    $end = CarbonImmutable::parse($to);

    while ($current->lte($end)) {
        $date = $current->toDateString();
        $covering = payableLinesCovering($preparationId, $date);

        expect($covering)->toHaveCount(1, "Expected exactly one payable line on {$date}");

        $current = $current->addDay();
    }
}

test('modern p2a to p4 exact handoff maps sign-on standby and onsite without overlap', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-10 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $p2aLines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::JoinStandby->value)
        ->get();
    $p4Lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::OnVessel->value)
        ->get();

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($p2aLines)->not->toBeEmpty()
        ->and($p2aLines->every(fn (CrewTimesheetPreparationLine $line): bool => $line->pay_category === CrewTimesheetPayCategory::SignOnStandby))->toBeTrue()
        ->and($p4Lines)->not->toBeEmpty()
        ->and($p4Lines->every(fn (CrewTimesheetPreparationLine $line): bool => $line->pay_category === CrewTimesheetPayCategory::Onsite))->toBeTrue()
        ->and(payableLinesCovering($preparation->id, '2026-07-15'))->toHaveCount(1)
        ->and(payableLinesCovering($preparation->id, '2026-07-15')->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);

    assertEachOperationalDayHasSinglePayableLine($preparation->id, '2026-07-10', '2026-07-20');

    $payload = legacyPayrollReviewPayload($fixtures, $preparation->id);

    expect(reviewPhaseCodes($payload, (int) $fixtures['employee']->id))->toBe(['p1a', 'p2']);
});

test('modern p2a p2b p2a p4 keeps sign-on categories without duplicate payable days', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::Training, 2, '2026-07-03 10:00:00', '2026-07-05 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 3, '2026-07-05 10:00:00', '2026-07-07 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 4, '2026-07-07 10:00:00', '2026-07-12 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payable = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->get();

    $signOnLines = $payable->where('pay_category', CrewTimesheetPayCategory::SignOnStandby);
    $onsiteLines = $payable->where('pay_category', CrewTimesheetPayCategory::Onsite);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($signOnLines)->not->toBeEmpty()
        ->and($onsiteLines)->not->toBeEmpty()
        ->and((float) $payable->sum('days'))->toBe(12.0)
        ->and($payable->pluck('crew_assignment_phase_id')->unique()->count())->toBe(4);

    assertEachOperationalDayHasSinglePayableLine($preparation->id, '2026-07-01', '2026-07-12');
});

test('p0 pre-arrival stays excluded from payable totals', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::PreArrival, 1, '2026-07-01 08:00:00', '2026-07-05 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 2, '2026-07-05 10:00:00', '2026-07-08 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 3, '2026-07-08 10:00:00', '2026-07-12 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $p0Lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::PreArrival->value)
        ->get();

    expect($p0Lines)->toHaveCount(1)
        ->and($p0Lines->first()->pay_category)->toBe(CrewTimesheetPayCategory::Excluded);
});

test('p3 demob standby maps to sign-off standby', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 2, '2026-07-10 10:00:00', '2026-07-15 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $p3Lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::DemobStandby->value)
        ->get();

    expect($p3Lines)->toHaveCount(1)
        ->and($p3Lines->first()->pay_category)->toBe(CrewTimesheetPayCategory::SignOffStandby)
        ->and(overlapWarningExists($preparation->id))->toBeFalse();
});

test('payroll review exposes six-phase codes without legacy markers', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-03 10:00:00', '2026-07-06 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payload = legacyPayrollReviewPayload($fixtures, $preparation->id);
    $phases = collect($payload['employees'][0]['assignments'][0]['phases'] ?? []);

    expect(reviewPhaseCodes($payload, (int) $fixtures['employee']->id))->toBe(['p1a', 'p2'])
        ->and($phases->every(fn (array $phase): bool => ($phase['is_legacy'] ?? false) === false))->toBeTrue()
        ->and($phases->every(fn (array $phase): bool => ($phase['legacy_context_label'] ?? null) === null))->toBeTrue();
});

test('correction presenter lists modern phase codes without legacy indicators', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Correction Phase Codes', $company);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $assignment->load([
        'phases.pendingCorrections',
        'corrections.requester:id,name',
        'corrections.decisionMaker:id,name',
        'corrections.phase',
        'corrections.company:id,timezone',
        'company:id,timezone',
    ]);

    $summary = app(CrewMovementCorrectionPresenter::class)->assignmentSummary($assignment);
    $correctable = collect($summary['correctable_phases']);

    expect($correctable->pluck('phase_code')->all())->toContain('p2')
        ->and($correctable->every(fn (array $phase): bool => ($phase['is_legacy'] ?? false) === false))->toBeTrue();
});
