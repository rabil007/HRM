<?php

use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('hybrid period allows manual operational entry without movement coverage', function () {
    ['company' => $company] = makePayrollFixtures();

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
    ]);

    expect($timesheet->source)->toBe(CrewTimesheetSource::Manual)
        ->and((float) $timesheet->onsite_days)->toBe(15.0)
        ->and($timesheet->isOperationallyLocked())->toBeFalse();
});

test('hybrid period allows import source operational entry without movement coverage', function () {
    ['company' => $company] = makePayrollFixtures();

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
    ]);

    expect($timesheet->source)->toBe(CrewTimesheetSource::Import)
        ->and((float) $timesheet->onsite_days)->toBe(15.0)
        ->and((float) $timesheet->overtime_hours)->toBe(10.0);
});

test('applying approved movement replaces import operational values and preserves financials', function () {
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

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and((float) $timesheet->onsite_days)->not->toBe(15.0)
        ->and((float) $timesheet->overtime_hours)->toBe(10.0)
        ->and((float) $timesheet->additional_amount)->toBe(500.0)
        ->and($timesheet->isOperationallyLocked())->toBeTrue()
        ->and((int) $timesheet->crew_timesheet_preparation_id)->toBe((int) $preparation->id)
        ->and($timesheet->movement_source_hash)->toBe($preparation->fresh()->source_hash);

    expect(Activity::query()
        ->where('description', 'Crew timesheet preparation applied to timesheets')
        ->exists())->toBeTrue();
});

test('manual and import cannot overwrite applied crew operations operational fields', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $locked = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();
    $originalOnsite = (float) $locked->onsite_days;

    expect(fn () => app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-20',
            'onsite_days' => 20,
            'source' => CrewTimesheetSource::Manual,
        ],
    ))->toThrow(ValidationException::class);

    $financial = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $fixtures['employee'],
        [
            'overtime_hours' => 8,
            'additional_amount' => 250,
            'source' => CrewTimesheetSource::Import,
        ],
    );

    expect((float) $financial->onsite_days)->toBe($originalOnsite)
        ->and($financial->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and((float) $financial->overtime_hours)->toBe(8.0)
        ->and((float) $financial->additional_amount)->toBe(250.0);
});

test('employee outside preparation remains editable after apply', function () {
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

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $otherTimesheet = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period']->fresh(),
        $other,
        [
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-14',
            'onsite_days' => 14,
            'source' => CrewTimesheetSource::Manual,
        ],
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

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);
    grantCompanyPermissions($approver, $fixtures['company'], [
        'payroll.periods.update',
        'payroll.periods.view',
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.view',
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $this->actingAs($approver)
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

test('hybrid generation returns employee-specific error for missing daily timesheet', function () {
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

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['affected_employee_id'])->toBe($employee->id)
        ->and($readiness['blocking_reason'])->toContain($employee->name)
        ->and($readiness['blocking_reason'])->toContain('missing a timesheet');

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
