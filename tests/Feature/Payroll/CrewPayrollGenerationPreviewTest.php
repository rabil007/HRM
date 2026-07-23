<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use Illuminate\Validation\ValidationException;

test('missing daily timesheet is skipped warning and does not block readiness when another employee is ready', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $ready = createCrewEmployeeWithContract($company, 'PREV-READY-1', 100, 50, 25);
    $missing = createCrewEmployeeWithContract($company, 'PREV-MISS-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $ready->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyCount)->toBe(1)
        ->and($preview->missingTimesheetCount)->toBe(1)
        ->and($preview->missingTimesheetEmployeeIds)->toContain($missing->id)
        ->and($preview->blockingCount)->toBe(0);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->readiness($period, (int) $company->id);
    expect($readiness['ready'])->toBeTrue()
        ->and($readiness['can_generate'])->toBeTrue()
        ->and($readiness['missing_timesheet_count'])->toBe(1);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $ready->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $missing->id)->exists())->toBeFalse();
});

test('unapproved manual and import timesheets are awaiting approval and skipped', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $manual = createCrewEmployeeWithContract($company, 'PREV-MAN-1', 100, 50, 25);
    $import = createCrewEmployeeWithContract($company, 'PREV-IMP-1', 100, 50, 25);

    CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $manual->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
    ]);
    CrewTimesheet::factory()->submitted()->create([
        'company_id' => $company->id,
        'employee_id' => $import->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 8,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeFalse()
        ->and($preview->awaitingApprovalCount)->toBe(2)
        ->and($preview->blockingCount)->toBe(0);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class, 'No employees are ready for payroll.');
});

test('approved manual and import timesheets are ready and generate payroll', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $manual = createCrewEmployeeWithContract($company, 'PREV-OK-M', 100, 50, 25);
    $import = createCrewEmployeeWithContract($company, 'PREV-OK-I', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $manual->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $import->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_days' => 12,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-12',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect()
        ->assertSessionHas('payroll_generation');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(2);
});

test('applied crew operations timesheet is ready without second approval', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->awaitingApprovalCount)->toBe(0)
        ->and($preview->blockingCount)->toBe(0);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update']);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.generate', $fixtures['period']))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $fixtures['period']->id)->exists())->toBeTrue();
});

test('mixed payroll generates ready employees and skips missing and unapproved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $approvedManual = createCrewEmployeeWithContract($company, 'MIX-MAN', 100, 50, 25);
    $approvedImport = createCrewEmployeeWithContract($company, 'MIX-IMP', 100, 50, 25);
    $missing = createCrewEmployeeWithContract($company, 'MIX-MISS', 100, 50, 25);
    $unapproved = createCrewEmployeeWithContract($company, 'MIX-WAIT', 100, 50, 25);
    $monthly = createCrewMonthlyEmployeeWithContract($company, 'MIX-MON', 5000, 1000, 500, 200);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $approvedManual->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $approvedImport->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);
    CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $unapproved->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 5,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(3)
        ->and($result->skippedMissingTimesheetCount)->toBe(1)
        ->and($result->skippedAwaitingApprovalCount)->toBe(1)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $missing->id)->exists())->toBeFalse()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $unapproved->id)->exists())->toBeFalse()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $monthly->id)->exists())->toBeTrue();
});

test('no ready employees returns validation and creates no payroll records', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, 'NONE-1', 100, 50, 25);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class, 'No employees are ready for payroll.');

    expect(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});

test('approved invalid timesheet is blocking and prevents generation', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'BAD-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_from' => '2026-07-10',
        'onsite_to' => '2026-07-01',
        'onsite_days' => 5,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeFalse()
        ->and($preview->blockingCount)->toBe(1)
        ->and($preview->blockingIssues[0]['code'])->toBe('invalid_approved_timesheet');

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class);
});

test('broken crew operations linkage is blocking', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CO-BAD-1', 100, 50, 25);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_days' => 10,
        'crew_timesheet_preparation_id' => null,
        'movement_source_hash' => 'stale-hash',
        'operational_approved_by' => null,
        'operational_approved_at' => null,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->blockingCount)->toBe(1)
        ->and($preview->blockingIssues[0]['code'])->toBe('crew_operations_linkage');
});

test('generation preview endpoint returns structured preview', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, 'PREV-API-1', 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('payroll.generation-preview', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertOk()
        ->assertJsonPath('missing_timesheet_count', 1)
        ->assertJsonPath('ready_count', 0)
        ->assertJsonPath('blocking_count', 0)
        ->assertJsonPath('can_generate', false)
        ->assertJsonMissingPath('ready_employee_ids');
});
