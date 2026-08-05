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
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\DeletePayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\PayslipData;
use App\Support\Payroll\PersistPayrollWorkAllocations;
use App\Support\Payroll\ResolveCrewContractForWorkDate;
use Illuminate\Validation\ValidationException;

test('daily crew prior-period onsite uses historical rates and current-period attendance only', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update', 'payroll.periods.view']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-4380', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 180,
        'site_allowance' => 0,
        'supplementary_allowance' => 0,
    ], '2026-06-01', 'June rates');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 220,
        'site_allowance' => 0,
        'supplementary_allowance' => 0,
    ], '2026-07-01', 'July rates');

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
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-15',
        'onsite_days' => 15,
        'sign_on_standby_days' => 0,
        'sign_off_standby_days' => 0,
        'overtime_hours' => 0,
        'additional_amount' => 0,
        'deduction_amount' => 0,
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

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(1);

    $record = PayrollRecord::query()
        ->where('company_id', $company->id)
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->gross_salary)->toBe('4380.00')
        ->and($record->net_salary)->toBe('4380.00')
        ->and((float) $record->present_days)->toBe(15.0)
        ->and((float) $record->leave_days)->toBe(16.0)
        ->and($record->calculation_breakdown['onsite_days'])->toEqual(15)
        ->and($record->calculation_breakdown['prior_period_adjustments'])->toHaveCount(1)
        ->and($record->calculation_breakdown['prior_period_adjustments'][0]['amount'])->toEqual(1080.0)
        ->and($record->calculation_breakdown['prior_period_adjustments'][0]['basic_daily_rate'])->toEqual(180.0)
        ->and($record->calculation_breakdown['current_period']['amount'])->toEqual(3300.0);

    expect(PayrollWorkAllocation::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('payroll_period_id', $period->id)
        ->count())->toBe(21)
        ->and(PayrollWorkAllocation::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('period_classification', PayrollWorkPeriodClassification::Prior)
            ->count())->toBe(6)
        ->and(PayrollWorkAllocation::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', PayrollWorkAllocationStatus::Reserved)
            ->count())->toBe(21);

    $payslip = PayslipData::for($record, (int) $company->id);
    $labels = collect($payslip['earnings'])->pluck('label')->all();

    expect($labels)->toContain('Prior-period onsite pay')
        ->and($labels)->toContain('Current-period onsite pay')
        ->and((float) $payslip['net_salary'])->toBe(4380.0);
});

test('already paid prior dates are excluded and only unpaid arrears are allocated', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-PARTIAL', 220, 0, 0);
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

    app(GenerateCrewPayroll::class)->handle($julyPeriod);

    $record = PayrollRecord::query()
        ->where('period_id', $julyPeriod->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->gross_salary)->toBe('3660.00')
        ->and($record->calculation_breakdown['prior_period']['payable_days'])->toBe(2)
        ->and($record->calculation_breakdown['prior_period']['requested_days'])->toBe(6)
        ->and(PayrollWorkAllocation::query()
            ->where('payroll_period_id', $julyPeriod->id)
            ->where('period_classification', PayrollWorkPeriodClassification::Prior)
            ->count())->toBe(2);
});

test('regenerating draft payroll replaces allocations idempotently', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-IDEM', 100, 0, 0);
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
        'onsite_days' => 5,
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

    app(GenerateCrewPayroll::class)->handle($period);
    $firstCount = PayrollWorkAllocation::query()->where('payroll_period_id', $period->id)->count();
    $firstNet = PayrollRecord::query()->where('period_id', $period->id)->value('net_salary');

    app(GenerateCrewPayroll::class)->handle($period->fresh());
    $secondCount = PayrollWorkAllocation::query()->where('payroll_period_id', $period->id)->count();
    $secondNet = PayrollRecord::query()->where('period_id', $period->id)->value('net_salary');

    expect($firstCount)->toBe(5)
        ->and($secondCount)->toBe(5)
        ->and($secondNet)->toBe($firstNet);
});

test('deleting a draft payroll record releases its work allocations', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-DEL', 100, 0, 0);
    $employee->fresh()->currentContract?->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);
    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'status' => PayrollPeriodStatus::Processing,
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

    $record = PayrollRecord::query()->where('period_id', $period->id)->firstOrFail();
    expect(PayrollWorkAllocation::query()->where('payroll_record_id', $record->id)->count())->toBe(2);

    app(DeletePayrollRecord::class)->handle($period->fresh(), $record);

    expect(PayrollWorkAllocation::query()->where('payroll_record_id', $record->id)->count())->toBe(0);
});

test('missing historical salary revision blocks generation for prior-period days', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-MISS-REV', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 220,
    ], '2026-07-01', 'July only');

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
        'from_date' => '2026-06-28',
        'to_date' => '2026-07-01',
        'days' => 4,
    ]);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class);
});

test('persist payroll work allocations enforces unique active employee work dates', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'ARREARS-UNIQUE', 100, 0, 0);
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

    $day = [
        'work_date' => '2026-06-15',
        'pay_category' => CrewTimesheetPayCategory::Onsite->value,
        'period_classification' => PayrollWorkPeriodClassification::Prior->value,
        'contract_id' => $contract->id,
        'salary_revision_id' => null,
        'basic_daily_rate' => 100,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 100,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 100,
        'crew_timesheet_segment_id' => null,
    ];

    app(PersistPayrollWorkAllocations::class)->replaceForRecord($periodA, $recordA, [$day]);

    expect(fn () => app(PersistPayrollWorkAllocations::class)->replaceForRecord($periodB, $recordB, [$day]))
        ->toThrow(ValidationException::class);
});

test('soft-deleted salary revisions are ignored for historical coverage', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-SOFT-REV', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 180,
    ], '2026-06-01', 'June rates');

    $revision = $contract->fresh()->salaryRevisions()->first();
    expect($revision)->not->toBeNull();
    $revision->delete();

    // All revisions soft-deleted → baseline contract components are allowed.
    $resolved = app(ResolveCrewContractForWorkDate::class)
        ->resolveSalaryRevision($contract->fresh(['salaryRevisions']), '2026-06-25');

    expect($resolved['revision'])->toBeNull()
        ->and($resolved['issue'])->toBeNull();
});
