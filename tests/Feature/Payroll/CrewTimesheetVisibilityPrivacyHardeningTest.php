<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
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
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use App\Support\Payroll\PayrollHubSummary;
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

test('crew timesheet-only user does not receive financial summary bank stats or payment proofs', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $fixtures['period']->update([
        'payment_proof_path' => 'payroll-periods/payment-proofs/ops-hidden.pdf',
        'payment_proof_paths' => ['payroll-periods/payment-proofs/ops-hidden.pdf'],
        'payment_date' => '2026-07-31',
    ]);

    PayrollRecord::factory()->crew()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['visibleEmployee']->id,
        'period_id' => $fixtures['period']->id,
        'gross_salary' => 1500,
        'net_salary' => 1400,
        'overtime_pay' => 50,
        'total_deductions' => 100,
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('permissions.view_financial', false)
            ->where('payroll_records_summary', null)
            ->where('employee_stats.total', 1)
            ->missing('employee_stats.with_bank_account')
            ->missing('employee_stats.missing_bank_account')
            ->missing('employee_stats.cash_payment_count')
            ->where('period.has_payment_proof', false)
            ->where('period.payment_proof_url', null)
            ->where('period.payment_proofs', [])
            ->where('period.payment_date', null)
            ->where('salary_input_type_options', [])
        );

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.payment-proof', $fixtures['period']))
        ->assertForbidden();
});

test('payroll-authorized user still receives financial summary bank stats and payment proof links', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $fixtures['period']->update([
        'payment_proof_path' => 'payroll-periods/payment-proofs/visible.pdf',
        'payment_proof_paths' => ['payroll-periods/payment-proofs/visible.pdf'],
        'payment_date' => '2026-07-31',
    ]);

    PayrollRecord::factory()->crew()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['visibleEmployee']->id,
        'period_id' => $fixtures['period']->id,
        'gross_salary' => 1500,
        'net_salary' => 1400,
    ]);

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('permissions.view_financial', true)
            ->where('payroll_records_summary.total_gross', fn ($value) => $value !== null)
            ->where('employee_stats.with_bank_account', fn ($value) => is_int($value))
            ->where('period.has_payment_proof', true)
            ->where('period.payment_proof_url', fn ($url) => is_string($url) && $url !== '')
            ->where('period.payment_date', '2026-07-31')
        );
});

test('crew timesheet-only user cannot infer banking status via employee_group filters', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', [
            $fixtures['period'],
            'employee_group' => 'with_bank_account',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('filters.employee_group', '')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $fixtures['visibleEmployee']->id)
        );

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', [
            $fixtures['period'],
            'employee_group' => 'missing_bank_account',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.employee_group', '')
            ->has('rows', 1)
        );

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', [
            $fixtures['period'],
            'crew_timesheet_filter' => 'awaiting_approval',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.crew_timesheet_filter', '')
        );
});

test('generation readiness for crew timesheet-only user excludes hidden employees', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $fixtures['hiddenTimesheet']->segments()->delete();
    $fixtures['hiddenTimesheet']->delete();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $fixtures['visibleEmployee']->id)
            ->where('period.generation_preview.ready_count', 1)
            ->where('period.generation_preview.missing_timesheet_count', 0)
            ->where('period.generation_preview.blocking_issues', [])
            ->where('period.generation_blocking_reason', null)
        );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
        [],
        $fixtures['opsUser'],
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->missingTimesheetCount)->toBe(0)
        ->and($preview->readyEmployeeIds)->toBe([(int) $fixtures['visibleEmployee']->id])
        ->and($preview->missingTimesheetEmployeeIds)->not->toContain((int) $fixtures['hiddenEmployee']->id);

    $encoded = json_encode($preview->toPublicArray());
    expect($encoded)->not->toContain($fixtures['hiddenEmployee']->name)
        ->and($encoded)->not->toContain((string) $fixtures['hiddenEmployee']->id);

    $unrestricted = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
        [],
        $fixtures['payrollUser'],
    );

    expect($unrestricted->missingTimesheetCount)->toBe(1)
        ->and($unrestricted->missingTimesheetEmployeeIds)->toContain((int) $fixtures['hiddenEmployee']->id);
});

test('crew timesheet-only user cannot download crew salary sheet export', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();
    $fixtures['period']->update(['status' => PayrollPeriodStatus::Approved]);

    PayrollRecord::factory()->crew()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['visibleEmployee']->id,
        'period_id' => $fixtures['period']->id,
        'gross_salary' => 1500,
        'net_salary' => 1400,
        'status' => 'approved',
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.export', $fixtures['period']))
        ->assertForbidden();
});

test('crew timesheet-only user can open crew period but not office period', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $officePeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'payroll_category' => PayrollCategory::Office,
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk();

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $officePeriod))
        ->assertForbidden();

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $officePeriod))
        ->assertOk();
});

test('crew timesheet-only user sees only crew periods on payroll index', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $officePeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'name' => 'July 2026 Office Hidden',
        'payroll_category' => PayrollCategory::Office,
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
        'payment_proof_path' => 'payroll-periods/payment-proofs/office.pdf',
        'payment_proof_paths' => ['payroll-periods/payment-proofs/office.pdf'],
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.index', [
            'all' => '1',
            'category' => 'office',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->where('permissions.view_financial', false)
            ->where('filters.category', 'crew')
            ->where('summary.office_periods', 0)
            ->where('summary.crew_periods', fn ($count) => (int) $count >= 1)
            ->where('periods', fn ($periods) => collect($periods)->every(
                fn ($period) => ($period['payroll_category'] ?? null) === 'crew'
                    && ($period['payment_proof_url'] ?? null) === null
                    && ($period['has_payment_proof'] ?? false) === false
            ))
            ->where('periods', fn ($periods) => collect($periods)->doesntContain(
                fn ($period) => (int) ($period['id'] ?? 0) === (int) $officePeriod->id
            ))
        );
});

test('hidden excluded employee ids and counts are not leaked to restricted crew users', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $fixtures['period']->update([
        'excluded_employee_ids' => [
            (int) $fixtures['visibleEmployee']->id,
            (int) $fixtures['hiddenEmployee']->id,
        ],
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.excluded_employee_ids', [(int) $fixtures['visibleEmployee']->id])
            ->where('period.generation_preview.excluded_count', 1)
        );

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.excluded_employee_ids', fn ($ids) => collect($ids)->contains((int) $fixtures['hiddenEmployee']->id)
                && collect($ids)->contains((int) $fixtures['visibleEmployee']->id))
            ->where('period.generation_preview.excluded_count', 2)
        );
});

test('restricted employee-scope user cannot populate crew timesheets from assignments', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    grantCompanyPermissions($fixtures['opsUser'], $fixtures['company'], [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.prepare',
    ], 'ops-crew-timesheet-role');
    restrictTestRoleEmployeeVisibility(
        $fixtures['opsUser'],
        $fixtures['company'],
        [(int) $fixtures['visibleDept']->id],
        'ops-crew-timesheet-role',
    );

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.prepare_timeline', false)
        );

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.prepare', $fixtures['period']))
        ->assertForbidden();
});

test('restricted crew user receives visibility-scoped payroll index progress', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    $secondVisible = createCrewEmployeeWithContract($fixtures['company'], 'VIS-CREW-2', 100, 50, 25);
    $secondVisible->update(['department_id' => $fixtures['visibleDept']->id]);

    foreach (['HID-CREW-2', 'HID-CREW-3'] as $employeeNo) {
        $hidden = createCrewEmployeeWithContract($fixtures['company'], $employeeNo, 100, 50, 25);
        $hidden->update(['department_id' => $fixtures['hiddenDept']->id]);

        CrewTimesheet::factory()->create([
            'company_id' => $fixtures['company']->id,
            'employee_id' => $hidden->id,
            'period_id' => $fixtures['period']->id,
            'source' => CrewTimesheetSource::Manual,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-05',
            'onsite_days' => 5,
        ]);
    }

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.index', ['all' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->where('periods', function ($periods) use ($fixtures) {
                $period = collect($periods)->firstWhere('id', $fixtures['period']->id);

                return $period !== null
                    && (int) $period['employee_count'] === 2
                    && (int) $period['timesheet_eligible_count'] === 2
                    && (int) $period['timesheets_filled_count'] === 1
                    && $period['timesheets_progress_label'] === '1/2'
                    && (int) $period['payroll_records_count'] === 0;
            })
        );
});

test('restricted crew user does not receive company-wide payroll record counts on index', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    PayrollRecord::factory()->crew()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['visibleEmployee']->id,
        'period_id' => $fixtures['period']->id,
        'gross_salary' => 1000,
        'net_salary' => 900,
        'calculation_breakdown' => ['salary_structure' => 'daily'],
    ]);

    PayrollRecord::factory()->crew()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['hiddenEmployee']->id,
        'period_id' => $fixtures['period']->id,
        'gross_salary' => 2000,
        'net_salary' => 1800,
        'calculation_breakdown' => ['salary_structure' => 'daily'],
    ]);

    $this->actingAs($fixtures['opsUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.index', ['all' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('periods', function ($periods) use ($fixtures) {
                $period = collect($periods)->firstWhere('id', $fixtures['period']->id);

                return $period !== null
                    && (int) $period['payroll_records_count'] === 0
                    && (int) $period['timesheet_eligible_count'] === 1
                    && (int) $period['timesheets_filled_count'] === 1
                    && $period['timesheets_progress_label'] === '1/1';
            })
        );

    $this->actingAs($fixtures['payrollUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.index', ['all' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('periods', function ($periods) use ($fixtures) {
                $period = collect($periods)->firstWhere('id', $fixtures['period']->id);

                return $period !== null
                    && (int) $period['payroll_records_count'] === 2
                    && (int) $period['timesheet_eligible_count'] === 2
                    && (int) $period['timesheets_filled_count'] === 2;
            })
        );
});

test('payroll hub incomplete_crew_runs uses visibility-scoped timesheet counts', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    // Visible: 3 employees / 2 timesheets → ops incomplete.
    // Hidden: 5 employees / 5 timesheets → must not make the ops period look complete.
    foreach (['VIS-CREW-2', 'VIS-CREW-3'] as $index => $employeeNo) {
        $visible = createCrewEmployeeWithContract($fixtures['company'], $employeeNo, 100, 50, 25);
        $visible->update(['department_id' => $fixtures['visibleDept']->id]);

        if ($index === 0) {
            CrewTimesheet::factory()->create([
                'company_id' => $fixtures['company']->id,
                'employee_id' => $visible->id,
                'period_id' => $fixtures['period']->id,
                'source' => CrewTimesheetSource::Manual,
                'onsite_from' => '2026-07-01',
                'onsite_to' => '2026-07-03',
                'onsite_days' => 3,
            ]);
        }
    }

    foreach (['HID-COMPLETE-2', 'HID-COMPLETE-3', 'HID-COMPLETE-4', 'HID-COMPLETE-5'] as $employeeNo) {
        $hidden = createCrewEmployeeWithContract($fixtures['company'], $employeeNo, 100, 50, 25);
        $hidden->update(['department_id' => $fixtures['hiddenDept']->id]);

        CrewTimesheet::factory()->create([
            'company_id' => $fixtures['company']->id,
            'employee_id' => $hidden->id,
            'period_id' => $fixtures['period']->id,
            'source' => CrewTimesheetSource::Manual,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-03',
            'onsite_days' => 3,
        ]);
    }

    $opsSummary = PayrollHubSummary::forCompany(
        (int) $fixtures['company']->id,
        '2026-07-01',
        '2026-07-31',
        [],
        $fixtures['opsUser'],
    );

    $payrollSummary = PayrollHubSummary::forCompany(
        (int) $fixtures['company']->id,
        '2026-07-01',
        '2026-07-31',
        [],
        $fixtures['payrollUser'],
    );

    // Ops: 2 visible timesheets / 3 visible employees → incomplete.
    // Hidden timesheets must not mark the ops period complete.
    expect($opsSummary['incomplete_crew_runs'])->toBe(1)
        ->and($opsSummary['office_periods'])->toBe(0)
        // Company-wide: 7 timesheets / 8 employees → also incomplete for financial users.
        ->and($payrollSummary['incomplete_crew_runs'])->toBe(1)
        ->and($payrollSummary['office_periods'])->toBe(0);
});

test('payroll hub incomplete_crew_runs stays incomplete for ops when only hidden timesheets are full', function () {
    $fixtures = makeCrewTimesheetVisibilityHardeningFixtures();

    // Remove the visible timesheet so ops has 1 employee / 0 timesheets.
    $fixtures['visibleTimesheet']->segments()->delete();
    $fixtures['visibleTimesheet']->delete();

    // Hidden department is fully covered (original + 4 more).
    foreach (['HID-FULL-2', 'HID-FULL-3', 'HID-FULL-4', 'HID-FULL-5'] as $employeeNo) {
        $hidden = createCrewEmployeeWithContract($fixtures['company'], $employeeNo, 100, 50, 25);
        $hidden->update(['department_id' => $fixtures['hiddenDept']->id]);

        CrewTimesheet::factory()->create([
            'company_id' => $fixtures['company']->id,
            'employee_id' => $hidden->id,
            'period_id' => $fixtures['period']->id,
            'source' => CrewTimesheetSource::Manual,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-02',
            'onsite_days' => 2,
        ]);
    }

    $opsSummary = PayrollHubSummary::forCompany(
        (int) $fixtures['company']->id,
        '2026-07-01',
        '2026-07-31',
        [],
        $fixtures['opsUser'],
    );

    // Pre-fix leak: company-wide timesheet count (5) >= visible employees (1) looked "complete".
    expect($opsSummary['incomplete_crew_runs'])->toBe(1);
});
