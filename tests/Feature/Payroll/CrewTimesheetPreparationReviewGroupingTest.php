<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function crewTimelineReviewPayload(array $fixtures, CrewTimesheetPreparation $preparation): array
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

function grantReviewGroupingPermissions(array $fixtures): void
{
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);
}

/**
 * @return array{preparation: CrewTimesheetPreparation, phase: CrewAssignmentPhase}
 */
function makeSingleAssignmentPreparation(array $fixtures): array
{
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
            'source_actual_start_at' => '2026-07-04 08:00:00',
            'source_actual_end_at' => '2026-07-10 18:00:00',
        ]);

    return compact('preparation', 'phase');
}

/**
 * @return array{
 *     preparation: CrewTimesheetPreparation,
 *     source: CrewAssignment,
 *     destination: CrewAssignment,
 *     sourcePhase: CrewAssignmentPhase,
 *     destinationPhase: CrewAssignmentPhase
 * }
 */
function makeLinkedAssignmentPreparation(array $fixtures, string $source): array
{
    $sourceAssignment = $fixtures['assignment'];
    $sourceAssignment->forceFill([
        'status' => CrewAssignmentStatus::Completed,
        'source' => 'manual',
    ])->save();

    $destinationVessel = makeCrewMovementVessel('Transfer Destination Vessel');

    $destination = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-TL-DEST-'.fake()->unique()->numerify('######'),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $destinationVessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => $source,
        'previous_assignment_id' => $sourceAssignment->id,
    ]);

    $sourcePhase = addTimelinePhase(
        $sourceAssignment,
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        '2026-07-08 12:00:00',
    );

    $destinationPhase = addTimelinePhase(
        $destination,
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-08 12:00:00',
        '2026-07-20 18:00:00',
    );

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($sourceAssignment, $sourcePhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-01',
            'to_date' => '2026-07-08',
            'days' => 8,
            'source_actual_start_at' => '2026-07-01 08:00:00',
            'source_actual_end_at' => '2026-07-08 12:00:00',
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($destination, $destinationPhase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-08',
            'to_date' => '2026-07-20',
            'days' => 13,
            'source_actual_start_at' => '2026-07-08 12:00:00',
            'source_actual_end_at' => '2026-07-20 18:00:00',
        ]);

    return [
        'preparation' => $preparation,
        'source' => $sourceAssignment,
        'destination' => $destination,
        'sourcePhase' => $sourcePhase,
        'destinationPhase' => $destinationPhase,
    ];
}

test('review payload groups one employee with one assignment and phase', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    ['preparation' => $preparation, 'phase' => $phase] = makeSingleAssignmentPreparation($fixtures);

    $payload = crewTimelineReviewPayload($fixtures, $preparation);
    $employee = $payload['employees'][0];

    expect($employee['assignment_count'])->toBe(1)
        ->and($employee['assignment_number'])->toBe($fixtures['assignment']->assignment_no)
        ->and($employee['vessel'])->toBe($fixtures['vessel']->name)
        ->and($employee['assignments'][0]['id'])->toBe($fixtures['assignment']->id)
        ->and($employee['assignments'][0]['source'])->toBe('manual')
        ->and($employee['assignments'][0]['source_label'])->toBe('Manual Assignment')
        ->and($employee['assignments'][0]['phases'][0]['id'])->toBe($phase->id)
        ->and($employee['assignments'][0]['phases'][0]['phase_code'])->toBe('p4')
        ->and($employee['assignments'][0]['phases'][0]['phase_code_display'])->toBe('P4')
        ->and($employee['assignments'][0]['phases'][0]['payable_days'])->toBe('7.00')
        ->and($employee['total_payable_days'])->toBe(7.0)
        ->and($employee['lines'])->toHaveCount(1);
});

test('review payload groups vessel transfer linked assignments separately', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    $linked = makeLinkedAssignmentPreparation($fixtures, 'vessel_transfer');

    $payload = crewTimelineReviewPayload($fixtures, $linked['preparation']);
    $employee = $payload['employees'][0];

    expect($employee['assignment_count'])->toBe(2)
        ->and($employee['assignment_number'])->toBeNull()
        ->and($employee['vessel'])->toBeNull()
        ->and($employee['assignments'][0]['id'])->toBe($linked['source']->id)
        ->and($employee['assignments'][0]['vessel'])->toBe($fixtures['vessel']->name)
        ->and($employee['assignments'][0]['source_label'])->toBe('Manual Assignment')
        ->and($employee['assignments'][1]['id'])->toBe($linked['destination']->id)
        ->and($employee['assignments'][1]['source'])->toBe('vessel_transfer')
        ->and($employee['assignments'][1]['source_label'])->toBe('Vessel Transfer')
        ->and($employee['assignments'][1]['previous_assignment_id'])->toBe($linked['source']->id)
        ->and($employee['assignments'][1]['previous_assignment_number'])->toBe($linked['source']->assignment_no)
        ->and($employee['total_payable_days'])->toBe(21.0);
});

test('review payload groups redeployment linked assignments separately', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    $linked = makeLinkedAssignmentPreparation($fixtures, 'redeployment');

    $payload = crewTimelineReviewPayload($fixtures, $linked['preparation']);
    $employee = $payload['employees'][0];

    expect($employee['assignment_count'])->toBe(2)
        ->and($employee['assignments'][1]['source'])->toBe('redeployment')
        ->and($employee['assignments'][1]['source_label'])->toBe('Redeployment')
        ->and($employee['assignments'][1]['previous_assignment_id'])->toBe($linked['source']->id);
});

test('warning-only lines merge into the related phase card without duplicating occurrences', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    ['preparation' => $preparation, 'phase' => $phase] = makeSingleAssignmentPreparation($fixtures);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'from_date' => '2026-07-11',
            'to_date' => '2026-07-11',
            'days' => 0,
            'warning_code' => CrewTimelineWarningCode::FutureActualDate->value,
            'remarks' => 'Future actual date on linked phase',
            'source_actual_start_at' => null,
            'source_actual_end_at' => null,
        ]);

    $payload = crewTimelineReviewPayload($fixtures, $preparation);
    $employee = $payload['employees'][0];
    $phaseCard = $employee['assignments'][0]['phases'][0];

    expect($employee['assignments'][0]['phases'])->toHaveCount(1)
        ->and($phaseCard['id'])->toBe($phase->id)
        ->and($phaseCard['actual_start'])->toBe('2026-07-04')
        ->and($phaseCard['actual_end'])->toBe('2026-07-10')
        ->and($phaseCard['is_operational'])->toBeTrue()
        ->and($phaseCard['payable_days'])->toBe('7.00')
        ->and($phaseCard['warnings'])->toHaveCount(1)
        ->and($phaseCard['warnings'][0]['code'])->toBe('future_actual_date')
        ->and($employee['informational_warning_count'])->toBe(1)
        ->and($employee['total_payable_days'])->toBe(7.0)
        ->and($employee['lines'])->toHaveCount(2);
});

test('two real on vessel phase occurrences remain separate cards', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);

    $first = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        '2026-07-05 18:00:00',
    );
    $second = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        2,
        '2026-07-10 08:00:00',
        '2026-07-15 18:00:00',
    );

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->create();

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $first)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-01',
            'to_date' => '2026-07-05',
            'days' => 5,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $second)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'from_date' => '2026-07-10',
            'to_date' => '2026-07-15',
            'days' => 6,
        ]);

    $payload = crewTimelineReviewPayload($fixtures, $preparation);
    $employee = $payload['employees'][0];

    expect($employee['assignments'][0]['phases'])->toHaveCount(2)
        ->and($employee['assignments'][0]['phases'][0]['id'])->toBe($first->id)
        ->and($employee['assignments'][0]['phases'][0]['occurrence'])->toBe(1)
        ->and($employee['assignments'][0]['phases'][1]['id'])->toBe($second->id)
        ->and($employee['assignments'][0]['phases'][1]['occurrence'])->toBe(2)
        ->and($employee['total_payable_days'])->toBe(11.0);
});

test('blocking and informational warning counts remain correct with merged phases', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    ['preparation' => $preparation, 'phase' => $phase] = makeSingleAssignmentPreparation($fixtures);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'days' => 0,
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'warning_code' => CrewTimelineWarningCode::FutureActualDate->value,
        ]);

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($fixtures['assignment'], $phase)
        ->create([
            'days' => 0,
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'warning_code' => CrewTimelineWarningCode::MissingActualStart->value,
        ]);

    $payload = crewTimelineReviewPayload($fixtures, $preparation);

    expect($payload['employees'][0]['blocking_warning_count'])->toBe(1)
        ->and($payload['employees'][0]['informational_warning_count'])->toBe(1)
        ->and($payload['summary']['blocking_warning_count'])->toBe(1)
        ->and($payload['summary']['informational_warning_count'])->toBe(1);
});

test('cross company preparation review remains isolated', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    ['preparation' => $preparation] = makeSingleAssignmentPreparation($fixtures);

    $other = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($other);

    expect(fn () => app(CrewTimesheetPreparationReviewQuery::class)->findForReview(
        $other['period'],
        (int) $preparation->id,
        (int) $other['company']->id,
    ))->toThrow(ModelNotFoundException::class);
});

test('review query does not n plus one when loading assignment phase hierarchy', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    $linked = makeLinkedAssignmentPreparation($fixtures, 'vessel_transfer');

    CrewTimesheetPreparationLine::factory()
        ->forPreparation($linked['preparation'])
        ->forAssignment($linked['destination'], $linked['destinationPhase'])
        ->create([
            'days' => 0,
            'pay_category' => CrewTimesheetPayCategory::Excluded,
            'warning_code' => CrewTimelineWarningCode::TimelineGap->value,
        ]);

    $loaded = app(CrewTimesheetPreparationReviewQuery::class)->findForReview(
        $fixtures['period'],
        (int) $linked['preparation']->id,
        (int) $fixtures['company']->id,
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    $payload = app(CrewTimesheetPreparationReviewResource::class)->toArray(
        $fixtures['period'],
        $loaded,
    );

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($payload['employees'][0]['assignment_count'])->toBe(2)
        ->and($queries->count())->toBeLessThan(8)
        ->and(
            $queries->filter(
                fn (array $query): bool => str_contains(strtolower($query['query']), 'select * from `crew_assignment_phases`')
                    || str_contains(strtolower($query['query']), 'select * from `crew_assignments`'),
            )->count()
        )->toBe(0);
});
