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
use Illuminate\Support\Facades\DB;

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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.assignment_count', 1)
            ->where('employees.0.assignment_number', $fixtures['assignment']->assignment_no)
            ->where('employees.0.vessel', $fixtures['vessel']->name)
            ->where('employees.0.assignments.0.id', $fixtures['assignment']->id)
            ->where('employees.0.assignments.0.source', 'manual')
            ->where('employees.0.assignments.0.source_label', 'Manual Assignment')
            ->where('employees.0.assignments.0.phases.0.id', $phase->id)
            ->where('employees.0.assignments.0.phases.0.phase_code', 'p4')
            ->where('employees.0.assignments.0.phases.0.phase_code_display', 'P4')
            ->where('employees.0.assignments.0.phases.0.payable_days', '7.00')
            ->where('employees.0.total_payable_days', 7)
            ->has('employees.0.lines', 1));
});

test('review payload groups vessel transfer linked assignments separately', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    $linked = makeLinkedAssignmentPreparation($fixtures, 'vessel_transfer');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $linked['preparation']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.assignment_count', 2)
            ->where('employees.0.assignment_number', null)
            ->where('employees.0.vessel', null)
            ->where('employees.0.assignments.0.id', $linked['source']->id)
            ->where('employees.0.assignments.0.vessel', $fixtures['vessel']->name)
            ->where('employees.0.assignments.0.source_label', 'Manual Assignment')
            ->where('employees.0.assignments.1.id', $linked['destination']->id)
            ->where('employees.0.assignments.1.source', 'vessel_transfer')
            ->where('employees.0.assignments.1.source_label', 'Vessel Transfer')
            ->where('employees.0.assignments.1.previous_assignment_id', $linked['source']->id)
            ->where('employees.0.assignments.1.previous_assignment_number', $linked['source']->assignment_no)
            ->where('employees.0.total_payable_days', 21));
});

test('review payload groups redeployment linked assignments separately', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    $linked = makeLinkedAssignmentPreparation($fixtures, 'redeployment');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $linked['preparation']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.assignment_count', 2)
            ->where('employees.0.assignments.1.source', 'redeployment')
            ->where('employees.0.assignments.1.source_label', 'Redeployment')
            ->where('employees.0.assignments.1.previous_assignment_id', $linked['source']->id));
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees.0.assignments.0.phases', 1)
            ->where('employees.0.assignments.0.phases.0.id', $phase->id)
            ->where('employees.0.assignments.0.phases.0.actual_start', '2026-07-04')
            ->where('employees.0.assignments.0.phases.0.actual_end', '2026-07-10')
            ->where('employees.0.assignments.0.phases.0.is_operational', true)
            ->where('employees.0.assignments.0.phases.0.payable_days', '7.00')
            ->has('employees.0.assignments.0.phases.0.warnings', 1)
            ->where('employees.0.assignments.0.phases.0.warnings.0.code', 'future_actual_date')
            ->where('employees.0.informational_warning_count', 1)
            ->where('employees.0.total_payable_days', 7)
            ->has('employees.0.lines', 2));
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees.0.assignments.0.phases', 2)
            ->where('employees.0.assignments.0.phases.0.id', $first->id)
            ->where('employees.0.assignments.0.phases.0.occurrence', 1)
            ->where('employees.0.assignments.0.phases.1.id', $second->id)
            ->where('employees.0.assignments.0.phases.1.occurrence', 2)
            ->where('employees.0.total_payable_days', 11));
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.blocking_warning_count', 1)
            ->where('employees.0.informational_warning_count', 1)
            ->where('summary.blocking_warning_count', 1)
            ->where('summary.informational_warning_count', 1));
});

test('cross company preparation review remains isolated', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($fixtures);
    ['preparation' => $preparation] = makeSingleAssignmentPreparation($fixtures);

    $other = makeDailyCrewTimelineFixtures();
    grantReviewGroupingPermissions($other);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->get(route('payroll.crew-timeline.show', [$other['period'], $preparation]))
        ->assertNotFound();
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
