<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Currency;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\ApprovePayrollPeriod;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\PayslipData;
use Illuminate\Validation\ValidationException;

test('approve is blocked after segment dates change since generation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'FRESH-SEG', 100, 0, 0);
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

    $segment = CrewTimesheetSegment::factory()->create([
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

    $segment->update([
        'from_date' => '2026-07-03',
        'to_date' => '2026-07-04',
        'days' => 2,
    ]);

    expect(fn () => app(ApprovePayrollPeriod::class)->handle($period->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('approve is blocked after backdated salary revision affects a work date', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'FRESH-REV', 100, 0, 0);
    $contract = $employee->fresh()->currentContract;
    $contract->update([
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

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 250,
        'site_allowance' => 0,
        'supplementary_allowance' => 0,
    ], '2026-07-01', 'Backdated July rate');

    expect(fn () => app(ApprovePayrollPeriod::class)->handle($period->fresh(), $user))
        ->toThrow(ValidationException::class);
});

test('approve succeeds when source fingerprint is unchanged', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'FRESH-OK', 100, 0, 0);
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

    expect($period->fresh()->status->value)->toBe('approved');
});

test('payslip uses snapshotted currency_code after company currency changes', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $aed = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );
    $company->update(['currency_id' => $aed->id]);

    $employee = createCrewEmployeeWithContract($company, 'CUR-SNAP', 100, 0, 0);
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

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record->calculation_breakdown['currency_code'] ?? null)->toBe('AED');

    $usd = Currency::query()->firstOrCreate(
        ['code' => 'USD'],
        ['name' => 'US Dollar', 'symbol' => '$', 'is_active' => true],
    );
    $company->update(['currency_id' => $usd->id]);

    $data = PayslipData::for($record->fresh(), $company->id);

    expect($data['currency_code'])->toBe('AED');
});
