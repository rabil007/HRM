<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('hybrid period allows manual operational entry without movement coverage', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'HYB-MAN-1', 100, 50, 25);

    $timesheet = app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => '2026-07-03',
        'sign_on_standby_days' => 3,
        'onsite_from' => '2026-07-04',
        'onsite_to' => '2026-07-18',
        'onsite_days' => 15,
        'source' => CrewTimesheetSource::Manual,
    ], $user->id);

    expect($timesheet->source)->toBe(CrewTimesheetSource::Manual)
        ->and($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and((float) $timesheet->onsite_days)->toBe(15.0)
        ->and($timesheet->isOperationallyLocked())->toBeFalse();
});

test('hybrid period allows import source operational entry without movement coverage', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'HYB-IMP-1', 100, 50, 25);

    $timesheet = app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-15',
        'onsite_days' => 15,
        'overtime_hours' => 10,
        'source' => CrewTimesheetSource::Import,
    ], $user->id);

    expect($timesheet->source)->toBe(CrewTimesheetSource::Import)
        ->and((float) $timesheet->onsite_days)->toBe(15.0)
        ->and((float) $timesheet->overtime_hours)->toBe(10.0);
});

test('populating from assignments replaces import operational values and preserves financials', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-15',
        'onsite_days' => 15,
        'overtime_hours' => 10,
        'additional_amount' => 500,
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-16 08:00:00', '2026-07-18 18:00:00');

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );
    $preparation = $result['preparation'];

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and((float) $timesheet->onsite_days)->not->toBe(15.0)
        ->and((float) $timesheet->overtime_hours)->toBe(10.0)
        ->and((float) $timesheet->additional_amount)->toBe(500.0)
        ->and($timesheet->isOperationallyLocked())->toBeFalse()
        ->and((int) $timesheet->crew_timesheet_preparation_id)->toBe((int) $preparation->id)
        ->and($timesheet->movement_source_hash)->toBe($preparation->fresh()->source_hash);
});

test('hybrid draft allows overwriting crew operations operational fields and financial-only updates', function () {
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

    $overwritten = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-20',
            'onsite_days' => 20,
            'source' => CrewTimesheetSource::Manual,
        ],
        $fixtures['user']->id,
    );

    expect((float) $overwritten->onsite_days)->toBe(20.0)
        ->and($overwritten->source)->toBe(CrewTimesheetSource::Manual)
        ->and($overwritten->isOperationallyLocked())->toBeFalse();

    $financial = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'overtime_hours' => 8,
            'additional_amount' => 250,
            'source' => CrewTimesheetSource::Import,
        ],
        $fixtures['user']->id,
    );

    expect((float) $financial->onsite_days)->toBe(20.0)
        ->and((float) $financial->overtime_hours)->toBe(8.0)
        ->and((float) $financial->additional_amount)->toBe(250.0);
});

test('employee outside preparation remains editable after populate', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    $other = createCrewEmployeeWithContract($fixtures['company'], 'HYB-OUT-1', 100, 50, 25);
    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $other->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 12,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-12',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-16 08:00:00', '2026-07-18 18:00:00');

    app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $otherTimesheet = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $other,
        [
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-14',
            'onsite_days' => 14,
            'source' => CrewTimesheetSource::Manual,
        ],
        $fixtures['user']->id,
    );

    expect($otherTimesheet->source)->toBe(CrewTimesheetSource::Manual)
        ->and((float) $otherTimesheet->onsite_days)->toBe(14.0)
        ->and($otherTimesheet->isOperationallyLocked())->toBeFalse();
});

test('hybrid generation succeeds for mixed crew operations import manual and monthly rows', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.update',
        'payroll.periods.view',
    ]);

    $imported = createCrewEmployeeWithContract($fixtures['company'], 'HYB-MIX-IMP', 100, 50, 25);
    $manual = createCrewEmployeeWithContract($fixtures['company'], 'HYB-MIX-MAN', 100, 50, 25);
    $monthly = createCrewMonthlyEmployeeWithContract($fixtures['company'], 'HYB-MIX-MON', 5000, 1000, 500, 250);

    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $imported->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $manual->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $monthly->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'unpaid_leave_days' => 0,
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-16 08:00:00', '2026-07-18 18:00:00');

    app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.generate', $fixtures['period']))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $fixtures['period']]))
        ->assertSessionHas('success');

    expect($fixtures['period']->fresh()->status)->toBe(PayrollPeriodStatus::Processing)
        ->and(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->count())->toBe(4);
});

test('hybrid generation does not require applied timeline when all employees use fallback data', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
        'payroll.crew_timesheets.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'HYB-FB-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 12,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-12',
    ]);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->readiness($period, (int) $company->id);
    expect($readiness['ready'])->toBeTrue()
        ->and($readiness['blocking_reason'])->toBeNull();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');
});

test('hybrid generation skips missing daily timesheet without blocking readiness', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'HYB-MISS-1', 100, 50, 25);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->readiness($period, (int) $company->id);

    expect($readiness['ready'])->toBeTrue()
        ->and($readiness['can_generate'])->toBeFalse()
        ->and($readiness['missing_timesheet_count'])->toBe(1)
        ->and($readiness['missing_timesheet_employee_ids'])->toContain($employee->id)
        ->and($readiness['blocking_count'])->toBe(0);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertSessionHasErrors('period_id');

    expect(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});

test('historical approved exclusive crew operations periods remain unchanged by hybrid migration', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->approved()->create([
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'crew_timesheet_mode' => CrewTimesheetMode::CrewOperations,
    ]);

    expect($period->fresh()->crew_timesheet_mode)->toBe(CrewTimesheetMode::CrewOperations)
        ->and($period->fresh()->requiresExclusiveCrewOperationsTimesheets())->toBeTrue()
        ->and($period->fresh()->usesMixedTimesheetSources())->toBeFalse()
        ->and($period->fresh()->status)->toBe(PayrollPeriodStatus::Approved);
});

test('hybrid payroll is tenant scoped for readiness', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    ['company' => $otherCompany] = makePayrollFixtures();

    expect(fn () => app(CrewOperationsPayrollGenerationGuard::class)->readiness(
        $fixtures['period'],
        (int) $otherCompany->id,
    ))->toThrow(HttpException::class);
});

test('office periods remain unchanged by hybrid crew behaviour', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    expect($period->crew_timesheet_mode)->toBeNull()
        ->and($period->usesCrewOperationsTimesheets())->toBeFalse()
        ->and($period->usesMixedTimesheetSources())->toBeFalse()
        ->and($period->payroll_category)->toBe(PayrollCategory::Office);
});
