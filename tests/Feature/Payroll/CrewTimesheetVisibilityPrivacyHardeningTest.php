<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     opsUser: User,
 *     payrollUser: User,
 *     company: Company,
 *     visibleDept: Department,
 *     hiddenDept: Department,
 *     visibleEmployee: Employee,
 *     hiddenEmployee: Employee,
 *     period: PayrollPeriod,
 *     visibleTimesheet: CrewTimesheet,
 *     hiddenTimesheet: CrewTimesheet
 * }
 */
function makeCrewTimesheetVisibilityHardeningFixtures(): array
{
    ['user' => $baseUser, 'company' => $company] = makePayrollFixtures();

    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Crew',
        'code' => 'VIS',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Crew',
        'code' => 'HID',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $visibleEmployee = createCrewEmployeeWithContract($company, 'VIS-CREW-1', 100, 50, 25);
    $visibleEmployee->update(['department_id' => $visibleDept->id]);
    $hiddenEmployee = createCrewEmployeeWithContract($company, 'HID-CREW-1', 100, 50, 25);
    $hiddenEmployee->update(['department_id' => $hiddenDept->id]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $visibleTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $visibleEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
        'overtime_hours' => 2,
        'additional_amount' => 100,
        'deduction_amount' => 25,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $visibleTimesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-10',
        'days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $hiddenTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $hiddenEmployee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
        'onsite_days' => 8,
        'overtime_hours' => 1,
        'additional_amount' => 50,
        'deduction_amount' => 10,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $hiddenTimesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-08',
        'days' => 8,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $opsUser = User::factory()->create();
    grantCompanyPermissions($opsUser, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ], 'ops-crew-timesheet-role');
    restrictTestRoleEmployeeVisibility($opsUser, $company, [(int) $visibleDept->id], 'ops-crew-timesheet-role');

    $payrollUser = User::factory()->create();
    grantCompanyPermissions($payrollUser, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ], 'payroll-financial-role');

    return [
        'opsUser' => $opsUser,
        'payrollUser' => $payrollUser,
        'company' => $company,
        'visibleDept' => $visibleDept,
        'hiddenDept' => $hiddenDept,
        'visibleEmployee' => $visibleEmployee->fresh(),
        'hiddenEmployee' => $hiddenEmployee->fresh(),
        'period' => $period,
        'visibleTimesheet' => $visibleTimesheet,
        'hiddenTimesheet' => $hiddenTimesheet,
        'baseUser' => $baseUser,
    ];
}

test('hidden employee direct segment mutation is rejected with 404', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['hiddenTimesheet'],
        ]), [
            'segments' => [[
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'from_date' => '2026-07-02',
                'to_date' => '2026-07-09',
            ]],
        ])
        ->assertNotFound();
});

test('hidden employee direct financial mutation is rejected with 404', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['hiddenTimesheet'],
        ]), [
            'overtime_hours' => 9,
        ])
        ->assertNotFound();
});

test('cross-company timesheet mutation is rejected with 404', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    $otherPeriod = PayrollPeriod::factory()->for($otherCompany)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $otherEmployee = createCrewEmployeeWithContract($otherCompany, 'XCO-1', 100, 50, 25);
    $otherTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'period_id' => $otherPeriod->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $otherPeriod,
            $otherTimesheet,
        ]), [
            'overtime_hours' => 4,
        ])
        ->assertNotFound();
});

test('non-draft payroll period operational edit is rejected', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();
    $fixtures['period']->update(['status' => PayrollPeriodStatus::Approved]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['visibleTimesheet'],
        ]), [
            'overtime_hours' => 12,
        ])
        ->assertSessionHasErrors();

    expect((float) $fixtures['visibleTimesheet']->fresh()->overtime_hours)->toBe(2.0);
});

test('crew timesheet user can edit OT hours but cannot edit monetary fields', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['visibleTimesheet'],
        ]), [
            'overtime_hours' => 6,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((float) $fixtures['visibleTimesheet']->fresh()->overtime_hours)->toBe(6.0);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['visibleTimesheet'],
        ]), [
            'additional_amount' => 999,
            'deduction_amount' => 888,
            'overtime_amount' => 777,
        ])
        ->assertForbidden();

    $fresh = $fixtures['visibleTimesheet']->fresh();
    expect((float) $fresh->additional_amount)->toBe(100.0)
        ->and((float) $fresh->deduction_amount)->toBe(25.0);
});

test('payroll-authorized user can edit monetary fields', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['visibleTimesheet'],
        ]), [
            'additional_amount' => 250,
            'deduction_amount' => 40,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $fixtures['visibleTimesheet']->fresh();
    expect((float) $fresh->additional_amount)->toBe(250.0)
        ->and((float) $fresh->deduction_amount)->toBe(40.0);
});

test('crew timesheet-only user does not receive salary rate bank or payroll sensitive props', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('permissions.view_financial', false)
            ->where('permissions.edit_monetary_timesheet_fields', false)
            ->where('payroll_records', [])
            ->where('salary_inputs_by_employee', [])
            ->where('payslip_summary', null)
            ->where('wps_preview', null)
            ->has('rows', 1)
            ->where('rows.0.employee.id', $fixtures['visibleEmployee']->id)
            ->where('rows.0.contract', null)
            ->where('rows.0.primary_account', null)
            ->missing('rows.0.timesheet.additional_amount')
            ->missing('rows.0.timesheet.deduction_amount')
            ->missing('rows.0.timesheet.overtime_amount')
            ->where('rows.0.timesheet.overtime_hours', '2.00')
        );
});

test('payroll-authorized user still receives required financial props', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('permissions.view_financial', true)
            ->where('permissions.edit_monetary_timesheet_fields', true)
            ->has('rows', 2)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['employee']['id'] === (int) $fixtures['visibleEmployee']->id
                && ($row['timesheet']['additional_amount'] ?? null) === '100.00'
                && ($row['contract'] ?? null) !== null))
        );
});

test('populate from crew assignments creates usable timesheet without mutating assignment history', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-07-01 08:00:00',
        '2026-07-15 18:00:00',
    );

    $phaseBefore = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', $fixtures['assignment']->id)
        ->orderBy('sequence')
        ->get(['id', 'actual_start_at', 'actual_end_at'])
        ->map(fn ($phase) => [
            'id' => $phase->id,
            'start' => $phase->actual_start_at?->toIso8601String(),
            'end' => $phase->actual_end_at?->toIso8601String(),
        ])
        ->all();

    app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->segments()->count())->toBeGreaterThan(0);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [$fixtures['period'], $timesheet]), [
            'segments' => [[
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'from_date' => '2026-07-03',
                'to_date' => '2026-07-12',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $phaseAfter = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', $fixtures['assignment']->id)
        ->orderBy('sequence')
        ->get(['id', 'actual_start_at', 'actual_end_at'])
        ->map(fn ($phase) => [
            'id' => $phase->id,
            'start' => $phase->actual_start_at?->toIso8601String(),
            'end' => $phase->actual_end_at?->toIso8601String(),
        ])
        ->all();

    expect($phaseAfter)->toBe($phaseBefore)
        ->and($timesheet->fresh()->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->fresh()->segments)->toHaveCount(1)
        ->and($timesheet->fresh()->segments->first()->from_date?->toDateString())->toBe('2026-07-03');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
    );

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyCount)->toBeGreaterThan(0);
});

test('non-bypassable cross-company integrity warning still blocks generation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
    ]);

    $preparation = CrewTimesheetPreparation::query()->create([
        'company_id' => $fixtures['company']->id,
        'payroll_period_id' => $fixtures['period']->id,
        'version' => 1,
        'status' => CrewTimesheetPreparationStatus::Applied,
        'source_hash' => 'integrity-hash',
        'applied_by' => $fixtures['user']->id,
        'applied_at' => now(),
    ]);

    CrewTimesheetPreparationLine::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'crew_assignment_phase_id' => null,
        'phase_code' => CrewPhaseCode::OnVessel,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-10',
        'days' => 10,
        'warning_code' => CrewTimelineWarningCode::CrossCompanyReference->value,
        'remarks' => 'Cross company',
    ]);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->readiness(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
    );

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['period_blocking_reason'])->toBe(
            CrewOperationsPayrollGenerationGuard::BLOCKING_WARNINGS_MESSAGE,
        );

    expect(fn () => app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh()))
        ->toThrow(ValidationException::class);
});
