<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Department;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\User;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;

test('already paid prior dates appear as non-blocking automatic adjustments', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update', 'payroll.periods.view']);

    $employee = createCrewEmployeeWithContract($company, 'PREV-ADJ-1', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 180,
    ], '2026-06-01', 'June');
    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 220,
    ], '2026-07-01', 'July');

    $junePeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'status' => PayrollPeriodStatus::Approved,
    ]);

    $juneRecord = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $junePeriod->id,
        'payroll_category' => PayrollCategory::Crew,
        'contract_id' => $contract->id,
        'status' => 'approved',
        'gross_salary' => 720,
        'net_salary' => 720,
    ]);

    foreach (['2026-06-25', '2026-06-26', '2026-06-27', '2026-06-28'] as $date) {
        PayrollWorkAllocation::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'payroll_period_id' => $junePeriod->id,
            'payroll_record_id' => $juneRecord->id,
            'work_date' => $date,
            'pay_category' => CrewTimesheetPayCategory::Onsite,
            'period_classification' => PayrollWorkPeriodClassification::Current,
            'status' => PayrollWorkAllocationStatus::Approved,
            'contract_id' => $contract->id,
            'basic_daily_rate' => 180,
            'site_allowance_daily_rate' => 0,
            'supplementary_allowance_daily_rate' => 0,
            'basic_amount' => 180,
            'site_allowance_amount' => 0,
            'supplementary_allowance_amount' => 0,
            'total_amount' => 180,
            'approved_at' => now(),
        ]);
    }

    $julyPeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $julyPeriod->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_days' => 15,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-25',
        'to_date' => '2026-07-15',
        'days' => 21,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $julyPeriod,
        (int) $company->id,
        [],
        $user,
    );

    $public = $preview->toPublicArray();
    $codes = collect($public['automatic_adjustments'])->pluck('code')->all();

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->automaticAdjustmentCount)->toBeGreaterThan(0)
        ->and($codes)->toContain('already_paid_prior_dates')
        ->and($codes)->toContain('prior_period_arrears_included')
        ->and(json_encode($public))->not->toContain('basic_daily_rate')
        ->and(json_encode($public))->not->toContain('daily rate of');
});

test('missing timesheet produces skipped issue with employee name and does not block', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $ready = createCrewEmployeeWithContract($company, 'SKIP-READY-1', 100, 50, 25);
    $missing = createCrewEmployeeWithContract($company, 'SKIP-MISS-1', 100, 50, 25);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $ready->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 5,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-05',
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $user,
    );
    $public = $preview->toPublicArray();

    expect($preview->canGenerate)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->skippedCount)->toBe(1)
        ->and($public['skipped_issues'][0]['code'])->toBe('missing_timesheet')
        ->and($public['skipped_issues'][0]['employee_name'])->toBe($missing->name)
        ->and($public)->not->toHaveKey('missing_timesheet_employee_ids');
});

test('hidden employee does not appear in skipped or automatic adjustment output', function () {
    ['company' => $company] = makePayrollFixtures();

    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Adj',
        'code' => 'VADJ',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Adj',
        'code' => 'HADJ',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $opsUser = User::factory()->create();
    grantCompanyPermissions($opsUser, $company, [
        'payroll.crew_timesheets.view',
        'payroll.periods.update',
    ], 'ops-preview-adj-role');
    restrictTestRoleEmployeeVisibility($opsUser, $company, [(int) $visibleDept->id], 'ops-preview-adj-role');

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $visibleReady = createCrewEmployeeWithContract($company, 'VIS-ADJ-READY', 100, 50, 25);
    $visibleReady->update(['department_id' => $visibleDept->id]);
    $visibleMissing = createCrewEmployeeWithContract($company, 'VIS-ADJ-MISS', 100, 50, 25);
    $visibleMissing->update(['department_id' => $visibleDept->id]);
    $hiddenMissing = createCrewEmployeeWithContract($company, 'HID-ADJ-MISS', 100, 50, 25);
    $hiddenMissing->update(['department_id' => $hiddenDept->id]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $visibleReady->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 4,
    ]);
    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-04',
        'days' => 4,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $opsUser,
    );

    $encoded = json_encode($preview->toPublicArray());

    expect($encoded)->toContain($visibleMissing->name)
        ->and($encoded)->not->toContain($hiddenMissing->name)
        ->and($encoded)->not->toContain((string) $hiddenMissing->id)
        ->and($preview->canGenerate)->toBeTrue();
});

test('automatic adjustments do not force can_generate false', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ADJ-READY-1', 100, 0, 0);
    $employee->fresh()->currentContract?->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_days' => 10,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-28',
        'to_date' => '2026-07-07',
        'days' => 10,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $user,
    );

    $codes = collect($preview->toPublicArray()['automatic_adjustments'])->pluck('code')->all();

    expect($preview->blockingCount)->toBe(0)
        ->and($preview->canGenerate)->toBeTrue()
        ->and($codes)->toContain('prior_period_arrears_included');
});
