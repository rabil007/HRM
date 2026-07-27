<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\NormalizeLegacyManualImportTimesheetApprovals;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;

test('newly saved manual timesheet is ready for payroll generation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'AUTO-MAN-1', 100, 50, 25);

    app(UpsertCrewTimesheet::class)->handle($period, $employee, [
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
        'onsite_days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ], $user->id);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeTrue()
        ->and($preview->readyCount)->toBe(1)
        ->and($preview->awaitingApprovalCount)->toBe(0)
        ->and($preview->readyEmployeeIds)->toContain($employee->id);
});

test('legacy unapproved manual and import timesheets remain awaiting approval until normalized', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $manual = createCrewEmployeeWithContract($company, 'LEG-MAN-1', 100, 50, 25);
    $import = createCrewEmployeeWithContract($company, 'LEG-IMP-1', 100, 50, 25);

    CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $manual->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);
    CrewTimesheet::factory()->submitted()->create([
        'company_id' => $company->id,
        'employee_id' => $import->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->awaitingApprovalCount)->toBe(2)
        ->and($preview->readyCount)->toBe(0);
});

test('legacy normalization approves valid manual and import rows on draft periods only', function () {
    ['company' => $company] = makePayrollFixtures();

    $draftPeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    $approvedPeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->approved()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $draftEmployee = createCrewEmployeeWithContract($company, 'NORM-DRF-1', 100, 50, 25);
    $approvedEmployee = createCrewEmployeeWithContract($company, 'NORM-APP-1', 100, 50, 25);

    $draftTimesheet = CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $draftEmployee->id,
        'period_id' => $draftPeriod->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 8,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-08',
    ]);

    $finalizedTimesheet = CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $approvedEmployee->id,
        'period_id' => $approvedPeriod->id,
        'source' => CrewTimesheetSource::Import,
        'onsite_days' => 8,
        'onsite_from' => '2026-06-01',
        'onsite_to' => '2026-06-08',
    ]);

    $result = app(NormalizeLegacyManualImportTimesheetApprovals::class)->handle($company);

    expect($result['normalized'])->toBe(1)
        ->and($draftTimesheet->fresh()->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($draftTimesheet->fresh()->approved_by)->toBeNull()
        ->and($finalizedTimesheet->fresh()->approval_status)->toBe(CrewTimesheetApprovalStatus::Draft);
});
