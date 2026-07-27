<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
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

test('legacy submitted import timesheet can still be returned through approval route', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.return',
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.return', [$period, $timesheet]), [
            'return_reason' => 'Correct onsite days',
        ])
        ->assertRedirect();

    expect($timesheet->fresh()->approval_status)->toBe(CrewTimesheetApprovalStatus::Returned)
        ->and($timesheet->fresh()->return_reason)->toBe('Correct onsite days');
});

test('unauthorized approval is rejected', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'APR-UNAUTH', 100, 50, 25);
    $timesheet = CrewTimesheet::factory()->submitted()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.approve', [$period, $timesheet]))
        ->assertForbidden();
});

test('cross company approval is rejected', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $other] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.approve']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $otherPeriod = PayrollPeriod::factory()->for($other)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($other, 'APR-XCO', 100, 50, 25);
    $timesheet = CrewTimesheet::factory()->submitted()->create([
        'company_id' => $other->id,
        'employee_id' => $employee->id,
        'period_id' => $otherPeriod->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.approve', [$period, $timesheet]))
        ->assertNotFound();
});

test('financial only update on locked crew operations preserves approval and operational fields', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $before = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    $updated = app(UpsertCrewTimesheet::class)->handle($fixtures['period'], $fixtures['employee'], [
        'overtime_hours' => 22,
        'additional_amount' => 150,
        'source' => CrewTimesheetSource::Manual,
    ], $approver->id);

    expect($updated->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($updated->isOperationallyLocked())->toBeTrue()
        ->and($updated->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and((float) $updated->onsite_days)->toBe((float) $before->onsite_days)
        ->and((float) $updated->overtime_hours)->toBe(22.0)
        ->and((float) $updated->additional_amount)->toBe(150.0);
});
