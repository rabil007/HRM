<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('open phase overnight makes previous unapplied preparation stale and new preparation includes newly eligible day', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    // Open active phase P4 starting 15 Sep
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-15 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $checker = app(CrewTimelineFreshnessChecker::class);
    $prepareService = app(PrepareCrewTimesheetTimeline::class);

    // Prepare on 17 Sep
    $v1 = $prepareService->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($checker->isFresh($v1, $fixtures['period']))->toBeTrue()
        ->and($v1->effective_cutoff_date?->toDateString())->toBe('2026-09-17');

    $onsiteV1 = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $v1->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->firstOrFail();

    expect($onsiteV1->from_date->toDateString())->toBe('2026-09-15')
        ->and($onsiteV1->to_date->toDateString())->toBe('2026-09-17')
        ->and((float) $onsiteV1->days)->toBe(3.0);

    // Advance clock to 18 Sep without changing any database records
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 10:00:00', 'Asia/Dubai'));

    // V1 must now be stale
    expect($checker->isFresh($v1, $fixtures['period']))->toBeFalse()
        ->and($checker->staleReason($v1, $fixtures['period']))->toBe(CrewTimelineFreshnessChecker::TIMELINE_ADVANCED_MESSAGE);

    // Generating a fresh preparation on 18 Sep allocates through 18 Sep
    $v2 = $prepareService->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($v2->version)->toBe(2)
        ->and($v2->effective_cutoff_date?->toDateString())->toBe('2026-09-18')
        ->and($checker->isFresh($v2, $fixtures['period']))->toBeTrue();

    $onsiteV2 = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $v2->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->firstOrFail();

    expect($onsiteV2->from_date->toDateString())->toBe('2026-09-15')
        ->and($onsiteV2->to_date->toDateString())->toBe('2026-09-18')
        ->and((float) $onsiteV2->days)->toBe(4.0);
});

test('completed historical timeline does not become stale when date advances', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    // Completed phase P4 ending on 17 Sep
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        '2026-09-17 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $checker = app(CrewTimelineFreshnessChecker::class);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue()
        ->and($preparation->effective_cutoff_date?->toDateString())->toBe('2026-09-17');

    // Advance clock to 18 Sep
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Dubai'));

    // Preparation must remain fresh because all phases are completed and payable result does not change
    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue()
        ->and($checker->staleReason($preparation, $fixtures['period']))->toBeNull();
});

test('explicit cutoff remains deterministic across days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $checker = app(CrewTimelineFreshnessChecker::class);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
        CarbonImmutable::parse('2026-09-17'),
    );

    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue()
        ->and($preparation->cutoff_date?->toDateString())->toBe('2026-09-17')
        ->and($preparation->effective_cutoff_date?->toDateString())->toBe('2026-09-17');

    // Advance clock to 18 Sep, 19 Sep
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Dubai'));
    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 12:00:00', 'Asia/Dubai'));
    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue();
});

test('payroll period end cutoff remains deterministic when active phase continues into next month', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-10 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $checker = app(CrewTimelineFreshnessChecker::class);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($preparation->effective_cutoff_date?->toDateString())->toBe('2026-08-31')
        ->and($checker->isFresh($preparation, $fixtures['period']))->toBeTrue();

    // Advancing September's current date does not change August's completed result
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Dubai'));
    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue();
});

test('company timezone midnight boundary resolves company-local today correctly', function () {
    // UTC 2026-09-17 20:05:00 is 2026-09-18 00:05:00 in Asia/Dubai (UTC+4)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 20:05:00', 'UTC'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-15 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    // Effective cutoff must be 18 Sep in Dubai, not 17 Sep in UTC
    expect($preparation->effective_cutoff_date?->toDateString())->toBe('2026-09-18');

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->firstOrFail();

    expect($onsite->to_date->toDateString())->toBe('2026-09-18')
        ->and((float) $onsite->days)->toBe(4.0);
});

test('movement correction invalidates existing preparation', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        '2026-09-15 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $checker = app(CrewTimelineFreshnessChecker::class);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($checker->isFresh($preparation, $fixtures['period']))->toBeTrue();

    // Create a pending correction on the phase
    CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $phase)
        ->pending()
        ->create([
            'company_id' => $fixtures['company']->id,
            'requested_by' => $fixtures['user']->id,
        ]);

    // Preparation must now be stale due to pending correction
    expect($checker->isFresh($preparation, $fixtures['period']))->toBeFalse()
        ->and($checker->staleReason($preparation, $fixtures['period']))->toBe(CrewTimelineFreshnessChecker::STALE_MESSAGE);
});

test('cancelled assignment with elapsed actual phase keeps standby days in preparation', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);

    // Assignment started standby, but vessel deployment was cancelled on 6 July
    // Elapsed standby was completed through 6 July, assignment marked cancelled
    $standbyPhase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::JoinStandby,
        1,
        '2026-07-01 08:00:00',
        '2026-07-06 18:00:00',
        CrewPhaseStatus::Completed,
    );

    // Cancelled assignment
    $fixtures['assignment']->update([
        'status' => CrewAssignmentStatus::Cancelled,
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $standbyLines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)
        ->where('days', '>', 0)
        ->get();

    expect($standbyLines)->toHaveCount(1)
        ->and($standbyLines->first()->from_date->toDateString())->toBe('2026-07-01')
        ->and($standbyLines->first()->to_date->toDateString())->toBe('2026-07-06')
        ->and((float) $standbyLines->first()->days)->toBe(6.0);
});

test('approved but stale preparation cannot be applied', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    // Submit and approve
    app(SubmitCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    app(ApproveCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved);

    // Advance clock to 18 Sep: open timeline has advanced
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Dubai'));

    // Attempt to apply must fail with validation exception
    expect(function () use ($fixtures, $preparation) {
        app(ApplyCrewTimesheetPreparation::class)->handle(
            $fixtures['period'],
            $preparation->fresh(),
            $fixtures['user'],
            (int) $fixtures['company']->id,
        );
    })->toThrow(ValidationException::class);

    // No timesheets were created
    expect(CrewTimesheet::query()->count())->toBe(0)
        ->and($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved);
});

test('applied preparation snapshot remains immutable when time advances', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    app(ApproveCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    // Apply on 17 Sep
    $result = app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($result->appliedEmployeeCount)->toBe(1)
        ->and($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect((float) $timesheet->onsite_days)->toBe(8.0) // 10 to 17 Sep = 8 days
        ->and($timesheet->onsite_to->toDateString())->toBe('2026-09-17');

    // Advance clock to 18 Sep
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Dubai'));

    // Applied timesheet must remain unmodified
    $timesheet->refresh();
    expect((float) $timesheet->onsite_days)->toBe(8.0)
        ->and($timesheet->onsite_to->toDateString())->toBe('2026-09-17');

    // Re-applying must be idempotent and not mutate the snapshot
    $idempotentResult = app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($idempotentResult->idempotent)->toBeTrue();
    $timesheet->refresh();
    expect((float) $timesheet->onsite_days)->toBe(8.0)
        ->and($timesheet->onsite_to->toDateString())->toBe('2026-09-17');
});

test('exact movement handoff preserves half-open semantics without duplicate dates or overlap warning', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    // P2A standby ends 10 Sep 14:00, P4 onsite starts 10 Sep 14:00
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::JoinStandby,
        1,
        '2026-09-05 08:00:00',
        '2026-09-10 14:00:00',
        CrewPhaseStatus::Completed,
    );
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        2,
        '2026-09-10 14:00:00',
        '2026-09-15 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    // No overlap warning
    $hasOverlapWarning = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('warning_code', 'overlapping_phases')
        ->exists();

    expect($hasOverlapWarning)->toBeFalse();

    $standbyLine = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)
        ->firstOrFail();

    $onsiteLine = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->firstOrFail();

    // 10 Sep is won by Onsite due to priority; Standby allocated 5 to 9 Sep (5 days)
    // Onsite allocated 10 to 15 Sep (6 days)
    expect($standbyLine->from_date->toDateString())->toBe('2026-09-05')
        ->and($standbyLine->to_date->toDateString())->toBe('2026-09-09')
        ->and((float) $standbyLine->days)->toBe(5.0)
        ->and($onsiteLine->from_date->toDateString())->toBe('2026-09-10')
        ->and($onsiteLine->to_date->toDateString())->toBe('2026-09-15')
        ->and((float) $onsiteLine->days)->toBe(6.0)
        ->and((float) $standbyLine->days + (float) $onsiteLine->days)->toBe(11.0);
});
