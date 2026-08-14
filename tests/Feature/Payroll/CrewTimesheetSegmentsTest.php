<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;

test('apply creates separate crew operations segments for two assignments without fake parent range', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'period' => $period, 'assignment' => $assignmentA] = $fixtures;

    $phaseA = addTimelinePhase($assignmentA, CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-11 12:00:00');
    $assignmentA->update([
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => '2026-07-01 08:00:00',
        'closed_at' => '2026-07-11 12:00:00',
        'current_phase_id' => $phaseA->id,
    ]);

    $vesselB = makeCrewMovementVessel('Segment Vessel B');
    $assignmentB = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-SEG-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vesselB->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'vessel_transfer',
        'previous_assignment_id' => $assignmentA->id,
        'started_at' => '2026-07-11 12:00:00',
    ]);
    $phaseB = addTimelinePhase($assignmentB, CrewPhaseCode::OnVessel, 1, '2026-07-11 12:00:00', null, CrewPhaseStatus::Active);
    $assignmentB->update(['current_phase_id' => $phaseB->id]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $period,
        (int) $company->id,
        (int) $user->id,
    );

    $approver = User::factory()->create();
    grantApplyPermissions($approver, $company);

    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'submitted_by' => $user->id,
        'submitted_at' => now(),
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    app(ApplyCrewTimesheetPreparation::class)->handle($period, $preparation->fresh(), $approver, $company->id);

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->with('segments')
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->segments->where('pay_category', CrewTimesheetPayCategory::Onsite)->count())->toBe(2)
        ->and($timesheet->segments->where('pay_category', CrewTimesheetPayCategory::Onsite)->pluck('crew_assignment_id')->sort()->values()->all())
        ->toEqual(collect([$assignmentA->id, $assignmentB->id])->sort()->values()->all())
        ->and((float) $timesheet->onsite_days)->toBe(
            (float) $timesheet->segments->where('pay_category', CrewTimesheetPayCategory::Onsite)->sum('days'),
        )
        ->and($timesheet->onsite_from)->toBeNull()
        ->and($timesheet->onsite_to)->toBeNull();
});

test('parent sync mirrors a single segment and nulls ranges for multiple segments', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['company' => $company, 'employee' => $employee, 'period' => $period] = $fixtures;

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 0,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-11',
        'days' => 11,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $synced = app(SyncCrewTimesheetParentFromSegments::class)->handle($timesheet->fresh());

    expect($synced->onsite_from?->toDateString())->toBe('2026-07-01')
        ->and($synced->onsite_to?->toDateString())->toBe('2026-07-11')
        ->and((float) $synced->onsite_days)->toBe(11.0);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 2,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-20',
        'to_date' => '2026-07-31',
        'days' => 12,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $synced = app(SyncCrewTimesheetParentFromSegments::class)->handle($timesheet->fresh(['segments']));

    expect($synced->onsite_from)->toBeNull()
        ->and($synced->onsite_to)->toBeNull()
        ->and((float) $synced->onsite_days)->toBe(23.0);
});

test('manual segment stores pay category, dates, days, and remarks without vessel, client, or rank columns', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CRW-SEG-'.uniqid(), 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-15',
                    'remarks' => 'Manual onsite period',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->first();

    $segment = $timesheet?->segments()->first();

    expect($segment)->not->toBeNull()
        ->and($segment->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and($segment->from_date->toDateString())->toBe('2026-07-01')
        ->and($segment->to_date->toDateString())->toBe('2026-07-15')
        ->and((float) $segment->days)->toBe(15.0)
        ->and($segment->remarks)->toBe('Manual onsite period')
        ->and($segment->crew_assignment_id)->toBeNull();
});
