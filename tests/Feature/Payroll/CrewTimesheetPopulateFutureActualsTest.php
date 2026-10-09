<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function makeOctoberPopulateFutureFixtures(): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);
    $fixtures['assignment']->update([
        'assignment_no' => 'CA-2026-000101',
    ]);

    return $fixtures;
}

function seedOctoberAbdulHamidMovements(array $fixtures): void
{
    // Arrival → vessel join → disembarkation → return home (all after company-local today)
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::JoinStandby,
        1,
        '2026-10-11 08:00:00',
        '2026-10-15 10:00:00',
        CrewPhaseStatus::Completed,
    );
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        2,
        '2026-10-15 10:00:00',
        '2026-10-27 10:00:00',
        CrewPhaseStatus::Completed,
    );
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::DemobStandby,
        3,
        '2026-10-27 10:00:00',
        '2026-10-31 10:00:00',
        CrewPhaseStatus::Completed,
    );
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::HomeRedeploy,
        4,
        '2026-10-31 10:00:00',
        null,
        CrewPhaseStatus::Active,
    );
}

function octoberPayableLine(CrewTimesheetPreparation $preparation, CrewTimesheetPayCategory $category): CrewTimesheetPreparationLine
{
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', $category)
        ->where('days', '>', 0)
        ->firstOrFail();
}

test('populate copies future completed p2a p4 p5 movements into october timesheet through period end', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $preparation = $result['preparation'];
    $standby = octoberPayableLine($preparation, CrewTimesheetPayCategory::SignOnStandby);
    $onsite = octoberPayableLine($preparation, CrewTimesheetPayCategory::Onsite);
    $signOff = octoberPayableLine($preparation, CrewTimesheetPayCategory::SignOffStandby);

    expect($result['was_refresh'])->toBeFalse()
        ->and($result['applied_employee_count'])->toBe(1)
        ->and($preparation->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and($preparation->effective_cutoff_date?->toDateString())->toBe('2026-10-30')
        ->and($standby->from_date->toDateString())->toBe('2026-10-11')
        ->and($standby->to_date->toDateString())->toBe('2026-10-14')
        ->and((float) $standby->days)->toBe(4.0)
        ->and($onsite->from_date->toDateString())->toBe('2026-10-15')
        ->and($onsite->to_date->toDateString())->toBe('2026-10-27')
        ->and((float) $onsite->days)->toBe(13.0)
        ->and($signOff->from_date->toDateString())->toBe('2026-10-28')
        ->and($signOff->to_date->toDateString())->toBe('2026-10-30')
        ->and((float) $signOff->days)->toBe(3.0)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('warning_code', CrewTimelineWarningCode::FutureActualDate->value)
                ->exists()
        )->toBeTrue();

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $fixtures['company']->id)
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and((float) $timesheet->sign_on_standby_days)->toBe(4.0)
        ->and((float) $timesheet->onsite_days)->toBe(13.0)
        ->and((float) $timesheet->sign_off_standby_days)->toBe(3.0)
        ->and($timesheet->sign_off_standby_to?->toDateString())->toBe('2026-10-30');
});

test('populate allocates active open phases through period end without inventing actual end', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-10-05 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $onsite = octoberPayableLine($result['preparation'], CrewTimesheetPayCategory::Onsite);

    expect($onsite->from_date->toDateString())->toBe('2026-10-05')
        ->and($onsite->to_date->toDateString())->toBe('2026-10-30')
        ->and((float) $onsite->days)->toBe(26.0)
        ->and($phase->fresh()->actual_end_at)->toBeNull()
        ->and($result['preparation']->effective_cutoff_date?->toDateString())->toBe('2026-10-30');
});

test('explicit cutoff clips populate before period end even when actuals continue later', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
        CarbonImmutable::parse('2026-10-20'),
    );

    $standby = octoberPayableLine($result['preparation'], CrewTimesheetPayCategory::SignOnStandby);
    $onsite = octoberPayableLine($result['preparation'], CrewTimesheetPayCategory::Onsite);

    expect($result['preparation']->cutoff_date?->toDateString())->toBe('2026-10-20')
        ->and($result['preparation']->effective_cutoff_date?->toDateString())->toBe('2026-10-20')
        ->and($standby->from_date->toDateString())->toBe('2026-10-11')
        ->and($standby->to_date->toDateString())->toBe('2026-10-14')
        ->and($onsite->from_date->toDateString())->toBe('2026-10-15')
        ->and($onsite->to_date->toDateString())->toBe('2026-10-20')
        ->and((float) $onsite->days)->toBe(6.0)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $result['preparation']->id)
                ->where('pay_category', CrewTimesheetPayCategory::SignOffStandby)
                ->where('days', '>', 0)
                ->exists()
        )->toBeFalse();
});

test('period clipping keeps november return-home day out of october payable allocation', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $payableDates = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $result['preparation']->id)
        ->where('days', '>', 0)
        ->get()
        ->flatMap(fn (CrewTimesheetPreparationLine $line) => [
            $line->from_date->toDateString(),
            $line->to_date->toDateString(),
        ]);

    expect($payableDates->max())->toBe('2026-10-30')
        ->and($payableDates)->not->toContain('2026-10-31')
        ->and($payableDates)->not->toContain('2026-11-01');
});

test('subsequent refresh supersedes applied preparation and remains idempotent for unchanged movements', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $first = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $second = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($first['was_refresh'])->toBeFalse()
        ->and($second['was_refresh'])->toBeTrue()
        ->and($first['preparation']->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Superseded)
        ->and($second['preparation']->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and($second['preparation']->version)->toBe(2)
        ->and((float) octoberPayableLine($second['preparation'], CrewTimesheetPayCategory::Onsite)->days)->toBe(13.0)
        ->and(
            CrewTimesheet::query()
                ->where('company_id', $fixtures['company']->id)
                ->where('period_id', $fixtures['period']->id)
                ->where('employee_id', $fixtures['employee']->id)
                ->count()
        )->toBe(1);
});

test('populate remains company isolated for future actual movements', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $other = makeDailyCrewTimelineFixtures();
    $other['company']->update(['timezone' => 'Asia/Dubai']);
    $other['period']->update([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);
    addTimelinePhase(
        $other['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-10-12 08:00:00',
        '2026-10-20 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $employeeIds = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $result['preparation']->id)
        ->pluck('employee_id')
        ->unique()
        ->values()
        ->all();

    expect($employeeIds)->toBe([(int) $fixtures['employee']->id])
        ->and($employeeIds)->not->toContain((int) $other['employee']->id)
        ->and(
            CrewTimesheet::query()
                ->where('company_id', $fixtures['company']->id)
                ->where('period_id', $fixtures['period']->id)
                ->where('employee_id', $other['employee']->id)
                ->exists()
        )->toBeFalse();
});

test('users without prepare permission cannot populate future actual crew timesheets', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    $unauthorized = User::factory()->create();
    grantCompanyPermissions($unauthorized, $fixtures['company'], [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    $this->actingAs($unauthorized)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertForbidden();

    expect(
        CrewTimesheet::query()
            ->where('company_id', $fixtures['company']->id)
            ->where('period_id', $fixtures['period']->id)
            ->exists()
    )->toBeFalse();
});

test('http populate for future completed movements redirects with success and writes timesheet days', function () {
    $fixtures = makeOctoberPopulateFutureFixtures();
    seedOctoberAbdulHamidMovements($fixtures);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertRedirect(route('payroll.show', $fixtures['period']))
        ->assertSessionHas('success', function (string $message): bool {
            return str_contains($message, 'Crew Timesheets populated from Crew Assignments');
        });

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $fixtures['company']->id)
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect((float) $timesheet->sign_on_standby_days)->toBe(4.0)
        ->and((float) $timesheet->onsite_days)->toBe(13.0)
        ->and((float) $timesheet->sign_off_standby_days)->toBe(3.0);
});
