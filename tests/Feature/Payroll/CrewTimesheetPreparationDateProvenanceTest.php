<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\CrewMovements\CrewDateProvenance;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewResource;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;

/**
 * @return array<string, mixed>
 */
function crewTimelineDateProvenancePayload(array $fixtures, CrewTimesheetPreparation $preparation): array
{
    $loaded = app(CrewTimesheetPreparationReviewQuery::class)->findForReview(
        $fixtures['period'],
        (int) $preparation->id,
        (int) $fixtures['company']->id,
    );

    return app(CrewTimesheetPreparationReviewResource::class)->toArray(
        $fixtures['period'],
        $loaded,
    );
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function grantDateProvenancePermissions(array $fixtures): void
{
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);
}

function makeSeptemberCrewTimelineFixtures(): array
{
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    return $fixtures;
}

function freezeCrewTimelineSeptember(string $localDateTime = '2026-09-15 12:00:00'): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($localDateTime, 'Asia/Dubai'));
}

test('payroll allocation dates are not labelled as planned schedule', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-04 08:00:00',
        '2026-07-10 18:00:00',
    );

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-10',
            'days' => 7,
        ]);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);
    $phaseCard = $payload['employees'][0]['assignments'][0]['phases'][0];

    expect($phaseCard)->not->toHaveKey('planned_start')
        ->and($phaseCard)->not->toHaveKey('planned_end')
        ->and($phaseCard)->not->toHaveKey('planned_date_origin')
        ->and($phaseCard)->not->toHaveKey('planned_date_origin_label')
        ->and($phaseCard)->not->toHaveKey('has_planned_schedule')
        ->and($phaseCard['payroll_from'])->toBe('2026-07-04')
        ->and($phaseCard['payroll_to'])->toBe('2026-07-10')
        ->and($phaseCard['payroll_date_origin'])->toBe(CrewDateProvenance::PayrollAllocation)
        ->and($phaseCard['payroll_period_label'])->toBe('Payroll allocation')
        ->and($phaseCard['actual_start'])->toBe('2026-07-04')
        ->and($phaseCard['actual_end'])->toBe('2026-07-10')
        ->and($phaseCard['payroll_lines'])->not->toBeEmpty()
        ->and($payload['employees'][0]['total_payable_days'])->toBe(7.0);
});

test('warning ranges are labelled as affected period and use phase actual dates', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-04 08:00:00',
        '2026-07-10 18:00:00',
    );

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-10',
            'days' => 7,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-04',
            'days' => 0,
            'warning_code' => CrewTimelineWarningCode::FutureActualDate->value,
            'source_actual_start_at' => null,
            'source_actual_end_at' => null,
        ]);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);
    $phaseCard = $payload['employees'][0]['assignments'][0]['phases'][0];

    expect($payload['employees'][0]['assignments'][0]['phases'])->toHaveCount(1)
        ->and($phaseCard)->not->toHaveKey('planned_start')
        ->and($phaseCard)->not->toHaveKey('has_planned_schedule')
        ->and($phaseCard['actual_start'])->toBe('2026-07-04')
        ->and($phaseCard['actual_end'])->toBe('2026-07-10')
        ->and($phaseCard['payroll_date_origin'])->toBe(CrewDateProvenance::PayrollAllocation)
        ->and($phaseCard['warnings'])->toHaveCount(1)
        ->and($payload['employees'][0]['total_payable_days'])->toBe(7.0)
        ->and($payload['employees'][0]['informational_warning_count'])->toBe(1);
});

test('phase planned dates are not exposed on the payroll review payload', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-04 08:00:00',
        '2026-07-10 18:00:00',
    );
    $phase->update([
        'planned_start_at' => '2026-07-01 00:00:00',
        'planned_end_at' => '2026-07-20 00:00:00',
    ]);

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-04',
            'to_date' => '2026-07-10',
            'days' => 7,
        ]);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);
    $phaseCard = $payload['employees'][0]['assignments'][0]['phases'][0];

    expect($phaseCard)->not->toHaveKey('planned_start')
        ->and($phaseCard)->not->toHaveKey('planned_end')
        ->and($phaseCard)->not->toHaveKey('planned_date_origin')
        ->and($phaseCard)->not->toHaveKey('planned_date_origin_label')
        ->and($phaseCard)->not->toHaveKey('has_planned_schedule')
        ->and($phaseCard['actual_start'])->toBe('2026-07-04')
        ->and($phaseCard['actual_end'])->toBe('2026-07-10')
        ->and($phaseCard['payroll_from'])->toBe('2026-07-04')
        ->and($phaseCard['payroll_to'])->toBe('2026-07-10')
        ->and($phaseCard['payroll_lines'])->not->toBeEmpty()
        ->and($phaseCard['warnings'])->toBeArray();
});

test('planned september overlap without actual overlap yields no payable september allocation', function () {
    freezeCrewTimelineSeptember();

    $fixtures = makeSeptemberCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-06-01 08:00:00',
        '2026-06-20 18:00:00',
    );
    $phase->update([
        'planned_start_at' => CarbonImmutable::parse('2026-09-01 08:00:00', 'Asia/Dubai'),
        'planned_end_at' => CarbonImmutable::parse('2026-09-30 18:00:00', 'Asia/Dubai'),
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(
        CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('days', '>', 0)
            ->count()
    )->toBe(0)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('pay_category', CrewTimesheetPayCategory::Onsite)
                ->sum('days')
        )->toBe(0);
});

test('completed june-august p4 with september planned sign-off yields zero september onsite days', function () {
    freezeCrewTimelineSeptember();

    $fixtures = makeSeptemberCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);
    $fixtures['assignment']->update([
        'planned_signoff_at' => CarbonImmutable::parse('2026-09-22 18:00:00', 'Asia/Dubai'),
    ]);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-06-24 08:00:00',
        '2026-08-07 18:00:00',
    );
    $phase->update([
        'planned_end_at' => CarbonImmutable::parse('2026-09-22 18:00:00', 'Asia/Dubai'),
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect(
        (float) CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->where('pay_category', CrewTimesheetPayCategory::Onsite)
            ->sum('days')
    )->toBe(0.0)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('days', '>', 0)
                ->count()
        )->toBe(0);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);

    expect($payload['employees'])->toHaveCount(0)
        ->and($payload['summary']['total_onsite_days'])->toBe('0.00');
});

test('active actual p4 is clipped to company-local today not planned sign-off', function () {
    freezeCrewTimelineSeptember();

    $fixtures = makeSeptemberCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);
    $fixtures['assignment']->update([
        'planned_signoff_at' => CarbonImmutable::parse('2026-09-22 18:00:00', 'Asia/Dubai'),
    ]);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-08-20 08:00:00',
        null,
        CrewPhaseStatus::Active,
    );
    $phase->update([
        'planned_end_at' => CarbonImmutable::parse('2026-09-22 18:00:00', 'Asia/Dubai'),
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $onsite = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->where('days', '>', 0)
        ->firstOrFail();

    expect($onsite->from_date->toDateString())->toBe('2026-09-01')
        ->and($onsite->to_date->toDateString())->toBe('2026-09-15')
        ->and((float) $onsite->days)->toBe(15.0);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);
    $employee = $payload['employees'][0];
    $phaseCard = $employee['assignments'][0]['phases'][0];

    expect($phaseCard)->not->toHaveKey('planned_start')
        ->and($phaseCard)->not->toHaveKey('planned_end')
        ->and($phaseCard)->not->toHaveKey('has_planned_schedule')
        ->and($employee['onsite_from'])->toBe('2026-09-01')
        ->and($employee['onsite_to'])->toBe('2026-09-15')
        ->and($employee['onsite_days'])->toBe(15.0)
        ->and($phaseCard['actual_start'])->toBe('2026-08-20')
        ->and($phaseCard['actual_end'])->toBeNull()
        ->and($phaseCard['payroll_from'])->toBe('2026-09-01')
        ->and($phaseCard['payroll_to'])->toBe('2026-09-15')
        ->and($phaseCard['payroll_lines'])->not->toBeEmpty();
});

test('planned window locates missing actual start as a zero-day warning only', function () {
    freezeCrewTimelineSeptember();

    $fixtures = makeSeptemberCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'planned_start_at' => CarbonImmutable::parse('2026-09-10 08:00:00', 'Asia/Dubai'),
        'planned_end_at' => CarbonImmutable::parse('2026-09-20 18:00:00', 'Asia/Dubai'),
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $warning = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('warning_code', CrewTimelineWarningCode::MissingActualStart->value)
        ->firstOrFail();

    expect((float) $warning->days)->toBe(0.0)
        ->and($warning->pay_category)->toBe(CrewTimesheetPayCategory::Excluded)
        ->and(
            CrewTimesheetPreparationLine::query()
                ->where('crew_timesheet_preparation_id', $preparation->id)
                ->where('days', '>', 0)
                ->count()
        )->toBe(0);

    $payload = crewTimelineDateProvenancePayload($fixtures, $preparation);
    $employee = $payload['employees'][0];
    $phaseCard = $employee['assignments'][0]['phases'][0];

    expect($phaseCard)->not->toHaveKey('planned_start')
        ->and($phaseCard)->not->toHaveKey('planned_end')
        ->and($phaseCard)->not->toHaveKey('has_planned_schedule')
        ->and($phaseCard['actual_start'])->toBeNull()
        ->and($phaseCard['actual_end'])->toBeNull()
        ->and($phaseCard['warnings'])->toHaveCount(1)
        ->and($phaseCard['warnings'][0]['code'])->toBe(CrewTimelineWarningCode::MissingActualStart->value)
        ->and($employee['total_payable_days'])->toBe(0.0)
        ->and($employee['onsite_days'])->toBe(0.0);
});

test('crew payroll review formats phase actual dates in the company timezone', function () {
    config(['app.timezone' => 'UTC']);

    $fixtures = makeDailyCrewTimelineFixtures();
    grantDateProvenancePermissions($fixtures);
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['assignment']->company_id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-09-16 01:30:00', 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse('2026-09-16 18:00:00', 'Asia/Dubai'),
    ]);

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-09-16',
            'to_date' => '2026-09-16',
            'days' => 1,
            'source_actual_start_at' => '2026-09-16 01:30:00',
            'source_actual_end_at' => '2026-09-16 18:00:00',
        ]);

    $loaded = app(CrewTimesheetPreparationReviewQuery::class)->findForReview(
        $fixtures['period'],
        (int) $preparation->id,
        (int) $fixtures['company']->id,
    );

    $payload = app(CrewTimesheetPreparationReviewResource::class)->toArray(
        $fixtures['period'],
        $loaded,
    );

    $loadedPhase = $loaded->lines->first()?->phase;

    expect(CompanyTimezone::forCompanyId((int) $fixtures['company']->id))
        ->toBe('Asia/Dubai')
        ->and($loaded->company_id)->toBe($fixtures['company']->id)
        ->and(CrewDateProvenance::phaseActual($loadedPhase, 'UTC')['start'])
        ->toBe('2026-09-15')
        ->and(CrewDateProvenance::phaseActual($loadedPhase, 'Asia/Dubai')['start'])
        ->toBe('2026-09-16');

    expect($payload['employees'][0]['assignments'][0]['phases'][0]['actual_start'])
        ->toBe('2026-09-16')
        ->and($payload['employees'][0]['assignments'][0]['phases'][0]['actual_end'])
        ->toBe('2026-09-16')
        ->and($payload['employees'][0]['total_payable_days'])->toBe(1.0)
        ->and($payload['employees'][0]['onsite_days'])->toBe(1.0);
});
