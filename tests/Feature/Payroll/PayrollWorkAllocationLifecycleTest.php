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
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Support\Payroll\Actions\ApprovePayrollPeriod;
use App\Support\Payroll\Actions\CancelPayrollPeriod;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\MarkPayrollPeriodPaid;
use App\Support\Payroll\Actions\RevertPayrollPeriodToProcessing;
use App\Support\Payroll\BuildDailyCrewPayrollAllocationPlan;
use Illuminate\Database\QueryException;

test('approval and paid transitions update allocation statuses', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
        'payroll.periods.mark_paid',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'ALLOC-LIFE', 100, 0, 0);
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
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_days' => 2,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-02',
        'days' => 2,
    ]);

    app(GenerateCrewPayroll::class)->handle($period);

    expect(PayrollWorkAllocation::query()
        ->where('payroll_period_id', $period->id)
        ->where('status', PayrollWorkAllocationStatus::Reserved)
        ->count())->toBe(2);

    app(ApprovePayrollPeriod::class)->handle($period->fresh(), $user);

    expect(PayrollWorkAllocation::query()
        ->where('payroll_period_id', $period->id)
        ->where('status', PayrollWorkAllocationStatus::Approved)
        ->whereNotNull('approved_at')
        ->count())->toBe(2);

    app(MarkPayrollPeriodPaid::class)->handle($period->fresh());

    expect(PayrollWorkAllocation::query()
        ->where('payroll_period_id', $period->id)
        ->where('status', PayrollWorkAllocationStatus::Paid)
        ->whereNotNull('paid_at')
        ->count())->toBe(2);
});

test('revert approved period to processing restores reserved allocations', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
        'payroll.periods.revert_to_processing',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'ALLOC-REVPROC', 100, 0, 0);
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
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_days' => 1,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'days' => 1,
    ]);

    app(GenerateCrewPayroll::class)->handle($period);
    app(ApprovePayrollPeriod::class)->handle($period->fresh(), $user);
    app(RevertPayrollPeriodToProcessing::class)->handle($period->fresh());

    $allocation = PayrollWorkAllocation::query()
        ->where('payroll_period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($allocation)->not->toBeNull()
        ->and($allocation->status)->toBe(PayrollWorkAllocationStatus::Reserved)
        ->and($allocation->approved_at)->toBeNull()
        ->and($allocation->active_allocation_key)->not->toBeNull()
        ->and($record)->not->toBeNull()
        ->and($record->status)->toBe('draft');
});

test('dates reserved by another open payroll block generation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ALLOC-RSV', 100, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    $openPeriod = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $openRecord = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $openPeriod->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'draft',
    ]);

    PayrollWorkAllocation::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_period_id' => $openPeriod->id,
        'payroll_record_id' => $openRecord->id,
        'work_date' => '2026-06-28',
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'period_classification' => PayrollWorkPeriodClassification::Prior,
        'status' => PayrollWorkAllocationStatus::Reserved,
        'contract_id' => $contract->id,
        'basic_daily_rate' => 100,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 100,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 100,
        'active_allocation_key' => sprintf('%d:%d:%s', $company->id, $employee->id, '2026-06-28'),
    ]);

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
        'onsite_days' => 1,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-28',
        'to_date' => '2026-07-01',
        'days' => 4,
    ]);

    $plan = app(BuildDailyCrewPayrollAllocationPlan::class)->handle($julyPeriod, $timesheet->fresh(['segments', 'employee']));

    expect($plan['issues'])->not->toBeEmpty()
        ->and(collect($plan['issues'])->pluck('code')->all())->toContain('reserved_conflict');
});

test('reversed allocations become eligible again and approved cancel preserves financial records', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
        'payroll.periods.cancel',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'ALLOC-REV', 100, 0, 0);
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
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_days' => 1,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'days' => 1,
    ]);

    app(GenerateCrewPayroll::class)->handle($period);
    app(ApprovePayrollPeriod::class)->handle($period->fresh(), $user);

    $recordBeforeCancel = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();
    expect($recordBeforeCancel)->not->toBeNull();

    app(CancelPayrollPeriod::class)->handle($period->fresh());

    $reversed = PayrollWorkAllocation::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('work_date', '2026-07-01')
        ->first();

    $recordAfterCancel = PayrollRecord::query()
        ->whereKey($recordBeforeCancel->id)
        ->first();

    expect($reversed)->not->toBeNull()
        ->and($reversed->status)->toBe(PayrollWorkAllocationStatus::Reversed)
        ->and($reversed->active_allocation_key)->toBeNull()
        ->and($reversed->payroll_record_id)->toBe($recordBeforeCancel->id)
        ->and($recordAfterCancel)->not->toBeNull()
        ->and($recordAfterCancel->status)->toBe('cancelled');

    $replacement = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'regular_period_key' => null,
    ]);

    $replacementTimesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $replacement->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_days' => 1,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $replacementTimesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'days' => 1,
    ]);

    app(GenerateCrewPayroll::class)->handle($replacement);

    expect(PayrollWorkAllocation::query()
        ->where('payroll_period_id', $replacement->id)
        ->where('status', PayrollWorkAllocationStatus::Reserved)
        ->where('work_date', '2026-07-01')
        ->count())->toBe(1);
});

test('active allocation key unique constraint prevents concurrent active duplicates', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'ALLOC-UNIQ', 100, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    $periodA = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    $periodB = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $recordA = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $periodA->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'draft',
    ]);
    $recordB = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $periodB->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'draft',
    ]);

    $attrs = [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-06-15',
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'period_classification' => PayrollWorkPeriodClassification::Prior,
        'status' => PayrollWorkAllocationStatus::Reserved,
        'contract_id' => $contract->id,
        'basic_daily_rate' => 100,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 100,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 100,
    ];

    PayrollWorkAllocation::query()->create([
        ...$attrs,
        'payroll_period_id' => $periodA->id,
        'payroll_record_id' => $recordA->id,
    ]);

    expect(fn () => PayrollWorkAllocation::query()->create([
        ...$attrs,
        'payroll_period_id' => $periodB->id,
        'payroll_record_id' => $recordB->id,
    ]))->toThrow(QueryException::class);
});
