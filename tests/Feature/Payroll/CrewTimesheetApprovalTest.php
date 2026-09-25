<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use Illuminate\Validation\ValidationException;

test('manual timesheet creation is automatically approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'APR-1', 100, 50, 25);

    $timesheet = app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ], $user->id);

    expect($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($timesheet->approved_by)->toBe($user->id)
        ->and($timesheet->approved_at)->not->toBeNull()
        ->and($timesheet->submitted_by)->toBeNull()
        ->and($timesheet->returned_by)->toBeNull();
});

test('manual timesheet editing remains approved and refreshes approver metadata', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'APR-EDIT', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_by' => $user->id,
        'approved_at' => now()->subDay(),
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);

    $updated = app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-12',
        'onsite_days' => 12,
        'source' => CrewTimesheetSource::Manual,
    ], $user->id);

    expect($updated->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($updated->approved_by)->toBe($user->id)
        ->and($updated->approved_at)->not->toBeNull();
});

test('manual timesheet save without authenticated actor is rejected', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'APR-NOACTOR', 100, 50, 25);

    expect(fn () => app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ]))->toThrow(ValidationException::class);
});

test('retired per-timesheet submit approve and return routes are unavailable', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'APR-RET-1', 100, 50, 25);
    $timesheet = CrewTimesheet::factory()->submitted()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 5,
    ]);

    foreach ([
        "/payroll/{$period->id}/timesheets/{$timesheet->id}/submit",
        "/payroll/{$period->id}/timesheets/{$timesheet->id}/approve",
        "/payroll/{$period->id}/timesheets/{$timesheet->id}/return",
    ] as $path) {
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->id])
            ->post($path, ['return_reason' => 'Correct onsite days'])
            ->assertNotFound();
    }
});

test('financial only update on crew operations preserves approval and operational fields', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-16 08:00:00', '2026-07-18 18:00:00');

    app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $before = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    $updated = app(UpsertCrewTimesheet::class)->handle($fixtures['period'], $fixtures['employee'], [
        'overtime_hours' => 22,
        'additional_amount' => 150,
        'source' => CrewTimesheetSource::Manual,
    ], $fixtures['user']->id);

    expect($updated->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($updated->isOperationallyLocked())->toBeFalse()
        ->and($updated->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and((float) $updated->onsite_days)->toBe((float) $before->onsite_days)
        ->and((float) $updated->overtime_hours)->toBe(22.0)
        ->and((float) $updated->additional_amount)->toBe(150.0);
});
