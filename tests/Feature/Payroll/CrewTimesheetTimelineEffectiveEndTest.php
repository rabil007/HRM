<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\CrewTimelinePhaseQuery;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function freezeCrewTimelineDubai(string $localDateTime): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($localDateTime, 'Asia/Dubai'));
}

function makeAugustCrewTimelineEffectiveEndFixtures(): array
{
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);

    return $fixtures;
}

function crewTimelinePayableLine(
    CrewTimesheetPreparation $preparation,
    CrewTimesheetPayCategory $category,
): CrewTimesheetPreparationLine {
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', $category)
        ->where('days', '>', 0)
        ->firstOrFail();
}

function crewTimelinePayableDays(
    CrewTimesheetPreparation $preparation,
    CrewTimesheetPayCategory $category,
): float {
    return (float) CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', $category)
        ->where('days', '>', 0)
        ->sum('days');
}

function assertCrewTimelineNoPayableDatesAfter(CrewTimesheetPreparation $preparation, string $effectiveEnd): void
{
    $lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->get();

    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        expect($line->from_date->toDateString())->toBeLessThanOrEqual($effectiveEnd)
            ->and($line->to_date->toDateString())->toBeLessThanOrEqual($effectiveEnd);
    }
}

test('ongoing p4 in the current month allocates through period end not company-local today', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(31.0)
        ->and($preparation->effective_cutoff_date?->toDateString())->toBe('2026-08-31');

    assertCrewTimelineNoPayableDatesAfter($preparation, '2026-08-31');
});

test('p4 that starts after month start allocates through period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-05 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-08-05')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(27.0);
});

test('standby then active p4 counts the handoff date once and continues through period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-08-02 08:00:00', '2026-08-05 10:00:00');
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        2,
        '2026-08-05 10:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $standby = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::SignOnStandby);
    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($standby->from_date->toDateString())->toBe('2026-08-02')
        ->and($standby->to_date->toDateString())->toBe('2026-08-04')
        ->and((float) $standby->days)->toBe(3.0)
        ->and($onsite->from_date->toDateString())->toBe('2026-08-05')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(27.0)
        ->and((float) $standby->days + (float) $onsite->days)->toBe(30.0);

    assertCrewTimelineNoPayableDatesAfter($preparation, '2026-08-31');
});

test('previous-month standby does not create august sign-on days when p4 starts on the first', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-26 08:00:00', '2026-08-01 10:00:00');
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        2,
        '2026-08-01 10:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect(crewTimelinePayableDays($preparation, CrewTimesheetPayCategory::SignOnStandby))->toBe(0.0)
        ->and($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(31.0);
});

test('earlier explicit cutoff clips active p4 before period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
        CarbonImmutable::parse('2026-08-10'),
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-10')
        ->and((float) $onsite->days)->toBe(10.0)
        ->and($preparation->cutoff_date?->toDateString())->toBe('2026-08-10');
});

test('explicit cutoff after today still authorizes payable days through that cutoff', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
        CarbonImmutable::parse('2026-08-20'),
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-20')
        ->and((float) $onsite->days)->toBe(20.0)
        ->and($preparation->cutoff_date?->toDateString())->toBe('2026-08-20');

    assertCrewTimelineNoPayableDatesAfter($preparation, '2026-08-20');
});

test('completed historical period still allocates through its period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $effectiveEnd = app(CrewTimelinePhaseQuery::class)->effectiveEndDate($fixtures['period'], null);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($effectiveEnd->toDateString())->toBe('2026-07-31')
        ->and($onsite->from_date->toDateString())->toBe('2026-07-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-07-31')
        ->and((float) $onsite->days)->toBe(31.0);
});

test('effective end uses company-local period end rather than utc or app timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-17 02:00:00', 'UTC'));

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    $fixtures['company']->update(['timezone' => 'America/New_York']);

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $effectiveEnd = app(CrewTimelinePhaseQuery::class)->effectiveEndDate($fixtures['period'], null);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-08-17')
        ->and(CarbonImmutable::now('Asia/Dubai')->toDateString())->toBe('2026-08-17')
        ->and(CarbonImmutable::now('America/New_York')->toDateString())->toBe('2026-08-16')
        ->and($effectiveEnd->toDateString())->toBe('2026-08-31')
        ->and($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(31.0);
});

test('planned sign-off does not generate payable days; open actual p4 continues to period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    $fixtures['assignment']->update([
        'planned_signoff_at' => CarbonImmutable::parse('2026-08-31 18:00:00', 'Asia/Dubai'),
    ]);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );
    $phase->update([
        'planned_start_at' => CarbonImmutable::parse('2026-08-01 08:00:00', 'Asia/Dubai'),
        'planned_end_at' => CarbonImmutable::parse('2026-08-31 18:00:00', 'Asia/Dubai'),
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $onsite->days)->toBe(31.0);

    assertCrewTimelineNoPayableDatesAfter($preparation, '2026-08-31');
});

test('active sign-off standby also continues through period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-08-01 08:00:00', '2026-08-10 10:00:00');
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::DemobStandby,
        2,
        '2026-08-10 10:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);
    $signOff = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::SignOffStandby);

    expect($onsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-08-10')
        ->and($signOff->from_date->toDateString())->toBe('2026-08-11')
        ->and($signOff->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $signOff->days)->toBe(21.0);

    assertCrewTimelineNoPayableDatesAfter($preparation, '2026-08-31');
});

test('future payroll periods allocate open actual phases through their own period end', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $septemberPeriod = PayrollPeriod::factory()
        ->for($fixtures['company'])
        ->crewOperations()
        ->create([
            'status' => PayrollPeriodStatus::Draft,
            'payroll_category' => PayrollCategory::Crew,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'payment_date' => '2026-09-30',
        ]);

    $effectiveEnd = app(CrewTimelinePhaseQuery::class)->effectiveEndDate($septemberPeriod, null);
    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $septemberPeriod,
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = crewTimelinePayableLine($preparation, CrewTimesheetPayCategory::Onsite);

    expect($effectiveEnd->toDateString())->toBe('2026-09-30')
        ->and($onsite->from_date->toDateString())->toBe('2026-09-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-09-30')
        ->and((float) $onsite->days)->toBe(30.0);
});

test('preparing a new version leaves previous snapshot lines unchanged', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $first = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $firstOnsite = crewTimelinePayableLine($first, CrewTimesheetPayCategory::Onsite);
    $firstLineId = $firstOnsite->id;

    freezeCrewTimelineDubai('2026-08-18 12:00:00');

    $second = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $firstOnsite->refresh();
    $secondOnsite = crewTimelinePayableLine($second, CrewTimesheetPayCategory::Onsite);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($firstOnsite->id)->toBe($firstLineId)
        ->and($firstOnsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($firstOnsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $firstOnsite->days)->toBe(31.0)
        ->and($secondOnsite->from_date->toDateString())->toBe('2026-08-01')
        ->and($secondOnsite->to_date->toDateString())->toBe('2026-08-31')
        ->and((float) $secondOnsite->days)->toBe(31.0);
});

test('effective-end clipping remains company isolated', function () {
    freezeCrewTimelineDubai('2026-08-17 12:00:00');

    $fixtures = makeAugustCrewTimelineEffectiveEndFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $other = makeDailyCrewTimelineFixtures();
    $other['period']->update([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);
    addTimelinePhase(
        $other['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-01 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $employeeIds = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->pluck('employee_id')
        ->unique()
        ->values()
        ->all();

    expect($employeeIds)->toBe([$fixtures['employee']->id])
        ->and($employeeIds)->not->toContain($other['employee']->id);
});
