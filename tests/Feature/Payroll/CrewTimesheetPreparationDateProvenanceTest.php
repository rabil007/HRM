<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Support\CrewMovements\CrewDateProvenance;

function grantDateProvenancePermissions(array $fixtures): void
{
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
    ]);
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.assignments.0.phases.0.planned_start', null)
            ->where('employees.0.assignments.0.phases.0.planned_end', null)
            ->where('employees.0.assignments.0.phases.0.has_planned_schedule', false)
            ->where('employees.0.assignments.0.phases.0.payroll_from', '2026-07-04')
            ->where('employees.0.assignments.0.phases.0.payroll_to', '2026-07-10')
            ->where('employees.0.assignments.0.phases.0.payroll_date_origin', CrewDateProvenance::PayrollAllocation)
            ->where('employees.0.assignments.0.phases.0.payroll_period_label', 'Payroll allocation')
            ->where('employees.0.assignments.0.phases.0.actual_start', '2026-07-04')
            ->where('employees.0.assignments.0.phases.0.actual_end', '2026-07-10')
            ->where('employees.0.total_payable_days', 7));
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees.0.assignments.0.phases', 1)
            ->where('employees.0.assignments.0.phases.0.has_planned_schedule', false)
            ->where('employees.0.assignments.0.phases.0.actual_start', '2026-07-04')
            ->where('employees.0.assignments.0.phases.0.actual_end', '2026-07-10')
            ->where('employees.0.assignments.0.phases.0.payroll_date_origin', CrewDateProvenance::PayrollAllocation)
            ->has('employees.0.assignments.0.phases.0.warnings', 1)
            ->where('employees.0.total_payable_days', 7)
            ->where('employees.0.informational_warning_count', 1));
});

test('explicit phase planned dates are exposed with provenance and never overwritten by payroll ranges', function () {
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

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employees.0.assignments.0.phases.0.has_planned_schedule', true)
            ->where('employees.0.assignments.0.phases.0.planned_start', '2026-07-01')
            ->where('employees.0.assignments.0.phases.0.planned_end', '2026-07-20')
            ->where('employees.0.assignments.0.phases.0.planned_date_origin', CrewDateProvenance::UserEntered)
            ->where('employees.0.assignments.0.phases.0.payroll_from', '2026-07-04')
            ->where('employees.0.assignments.0.phases.0.payroll_to', '2026-07-10'));
});
