<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('new crew payroll period defaults to hybrid timesheet mode and office periods store null mode', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();

    expect($period->crew_timesheet_mode)->toBe(CrewTimesheetMode::Hybrid);

    $officePeriod = PayrollPeriod::factory()->for($company)->office()->create();

    expect($officePeriod->crew_timesheet_mode)->toBeNull();
});

test('authorized users can store crew periods without selecting a timesheet mode', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Crew',
            'payroll_category' => 'crew',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertRedirect(route('payroll.index'));

    $this->assertDatabaseHas('payroll_periods', [
        'company_id' => $company->id,
        'name' => 'July 2026 Crew',
        'payroll_category' => PayrollCategory::Crew->value,
        'crew_timesheet_mode' => CrewTimesheetMode::Hybrid->value,
        'regular_period_key' => 'company:'.$company->id.':crew:2026-07',
    ]);
});

test('office payroll period store rejects crew timesheet mode', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Office',
            'payroll_category' => 'office',
            'crew_timesheet_mode' => 'manual',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertSessionHasErrors('crew_timesheet_mode');
});

test('crew timesheet mode cannot change after a timesheet exists', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-MODE-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 1,
        'onsite_days' => 1,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.periods.crew-timesheet-mode', $period), [
            'crew_timesheet_mode' => 'crew_operations',
        ])
        ->assertSessionHasErrors('crew_timesheet_mode');
});

test('prepare is blocked in manual timesheet mode with the expected message', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Manual]);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.prepare',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertSessionHasErrors([
            'payroll_period_id' => 'Crew Operations timeline preparation is not available for this pay period.',
        ]);
});

test('prepare is allowed in hybrid timesheet mode', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertRedirect();

    expect($fixtures['period']->fresh()->usesCrewOperationsTimesheets())->toBeTrue()
        ->and($fixtures['period']->fresh()->usesMixedTimesheetSources())->toBeTrue();
});

test('prepare is allowed in crew operations mode', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertRedirect();

    expect($fixtures['period']->fresh()->usesCrewOperationsTimesheets())->toBeTrue();
});

test('manual crew payroll generation works without applied timeline preparation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
        'payroll.crew_timesheets.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'payment_date' => '2026-06-30',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-MAN-1', 150, 50, 75);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Processing)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBeGreaterThan(0);
});

test('crew operations payroll generation is blocked without an applied timeline', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.update',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.generate', $fixtures['period']))
        ->assertSessionHasErrors([
            'period_id' => CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE,
        ]);

    expect(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->exists())->toBeFalse();
});

test('crew operations payroll generation succeeds after approved timeline is applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.update',
    ]);

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.update',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.generate', $fixtures['period']))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $fixtures['period']]))
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->exists())->toBeTrue();
});

test('payroll show exposes mode, generation readiness, and timeline props for crew operations periods', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.crew_timesheet_mode', CrewTimesheetMode::CrewOperations->value)
            ->where('period.uses_crew_operations_timesheets', true)
            ->where('period.generation_ready', false)
            ->where('generation_readiness.ready', false)
            ->where(
                'generation_readiness.blocking_reason',
                CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE,
            )
            ->has('crew_timesheet_mode_options', 3));
});

test('payroll show hides generation readiness blocking for manual crew periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.uses_manual_timesheets', true)
            ->where('period.generation_ready', true)
            ->where('generation_readiness.ready', true)
            ->where('crew_timeline_preparation', null));
});

test('daily operational upsert is blocked in crew operations mode before applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    expect(fn () => app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        [
            'sign_on_standby_from' => '2026-07-01',
            'sign_on_standby_to' => '2026-07-03',
            'sign_on_standby_days' => 3,
            'onsite_from' => '2026-07-04',
            'onsite_to' => '2026-07-10',
            'onsite_days' => 7,
            'overtime_hours' => 2,
        ],
    ))->toThrow(ValidationException::class);
});

test('daily financial upsert is allowed in crew operations mode before applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $timesheet = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        [
            'overtime_hours' => 4,
            'remarks' => 'OT only',
        ],
    );

    expect($timesheet->overtime_hours)->toBe('4.00')
        ->and($timesheet->remarks)->toBe('OT only')
        ->and($timesheet->sign_on_standby_days)->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::Manual);
});

test('crew timesheet mode cannot change after a preparation exists', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.periods.update',
    ]);

    CrewTimesheetPreparation::factory()->forPeriod($fixtures['period'])->create([
        'prepared_by' => $fixtures['user']->id,
        'status' => CrewTimesheetPreparationStatus::Draft,
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.periods.crew-timesheet-mode', $fixtures['period']), [
            'crew_timesheet_mode' => 'manual',
        ])
        ->assertSessionHasErrors('crew_timesheet_mode');
});

test('cross-company crew timesheet mode update returns 404', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['user' => $otherUser, 'company' => $otherCompany] = makePayrollFixtures();
    grantCompanyPermissions($otherUser, $otherCompany, ['payroll.periods.update']);

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->put(route('payroll.periods.crew-timesheet-mode', $fixtures['period']), [
            'crew_timesheet_mode' => 'manual',
        ])
        ->assertNotFound();
});

test('existing crew periods are backfilled to manual by migration semantics', function () {
    ['company' => $company] = makePayrollFixtures();

    $periodId = DB::table('payroll_periods')->insertGetId([
        'company_id' => $company->id,
        'payroll_category' => 'crew',
        'crew_timesheet_mode' => null,
        'name' => 'Legacy Crew',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'payment_date' => '2026-02-05',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('payroll_periods')
        ->where('payroll_category', 'crew')
        ->whereNull('crew_timesheet_mode')
        ->update(['crew_timesheet_mode' => 'manual']);

    expect(DB::table('payroll_periods')->where('id', $periodId)->value('crew_timesheet_mode'))
        ->toBe('manual');

    $officeId = DB::table('payroll_periods')->insertGetId([
        'company_id' => $company->id,
        'payroll_category' => 'office',
        'crew_timesheet_mode' => null,
        'name' => 'Legacy Office',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'payment_date' => '2026-02-05',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('payroll_periods')->where('id', $officeId)->value('crew_timesheet_mode'))
        ->toBeNull();
});
