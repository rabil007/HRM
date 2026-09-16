<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignmentPhase;
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

    expect(reviewPhaseCodes($payload, (int) $fixtures['employee']->id))->toBe(['p2a', 'p4']);
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

test('legacy p1 travel in stays excluded from payable totals', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::TravelIn, 1, '2026-07-05 08:00:00', '2026-07-08 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-08 10:00:00', '2026-07-12 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $p1Lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::TravelIn->value)
        ->get();

    expect($p1Lines)->toHaveCount(1)
        ->and($p1Lines->first()->pay_category)->toBe(CrewTimesheetPayCategory::Excluded)
        ->and((float) $p1Lines->first()->days)->toBeGreaterThan(0);
});

test('legacy p3 ready to join maps to sign-on standby', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 1, '2026-07-10 08:00:00', '2026-07-15 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-15 10:00:00', '2026-07-20 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $p3Lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('phase_code', CrewPhaseCode::ReadyToJoin->value)
        ->get();

    expect($p3Lines)->toHaveCount(1)
        ->and($p3Lines->first()->pay_category)->toBe(CrewTimesheetPayCategory::SignOnStandby)
        ->and(overlapWarningExists($preparation->id))->toBeFalse();
});

test('payroll review marks actual legacy p1 and p3 phases with legacy context', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::TravelIn, 1, '2026-07-01 08:00:00', '2026-07-03 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 2, '2026-07-03 10:00:00', '2026-07-06 10:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 3, '2026-07-06 10:00:00', '2026-07-10 10:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payload = legacyPayrollReviewPayload($fixtures, $preparation->id);
    $phases = collect($payload['employees'][0]['assignments'][0]['phases'] ?? []);

    expect($phases->firstWhere('phase_code', 'p1'))
        ->not->toBeNull()
        ->and($phases->firstWhere('phase_code', 'p1')['is_legacy'])->toBeTrue()
        ->and($phases->firstWhere('phase_code', 'p1')['legacy_context_label'])->toBe('Legacy phase · P1 Travel In')
        ->and($phases->firstWhere('phase_code', 'p3'))
        ->not->toBeNull()
        ->and($phases->firstWhere('phase_code', 'p3')['is_legacy'])->toBeTrue()
        ->and($phases->firstWhere('phase_code', 'p3')['legacy_context_label'])->toBe('Legacy phase · P3 Ready to Join')
        ->and($phases->firstWhere('phase_code', 'p4')['is_legacy'] ?? false)->toBeFalse();
});

test('correction presenter keeps legacy p1 and p3 correctable with legacy indicator', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Correction Vessel', $company);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $p1 = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 0,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-01-01 08:00:00', $company->timezone ?? 'UTC'),
        'actual_end_at' => CarbonImmutable::parse('2026-01-02 08:00:00', $company->timezone ?? 'UTC'),
    ]);

    $p3 = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-01-02 08:00:00', $company->timezone ?? 'UTC'),
        'actual_end_at' => CarbonImmutable::parse('2026-01-03 08:00:00', $company->timezone ?? 'UTC'),
    ]);

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

    expect($correctable->pluck('phase_code')->all())->toContain('p1', 'p3', 'p4')
        ->and($correctable->firstWhere('phase_code', 'p1')['is_legacy'])->toBeTrue()
        ->and($correctable->firstWhere('phase_code', 'p1')['legacy_context_label'])->toBe('Legacy phase · P1 Travel In')
        ->and($correctable->firstWhere('phase_code', 'p3')['is_legacy'])->toBeTrue()
        ->and($correctable->firstWhere('phase_code', 'p4')['is_legacy'] ?? false)->toBeFalse();
});
