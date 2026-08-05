<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;

test('active_allocation_key cannot be mass assigned via fill', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'KEY-FILL', 100, 0, 0);
    $contract = $employee->fresh()->currentContract;

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $record = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'draft',
    ]);

    $allocation = new PayrollWorkAllocation;
    $allocation->fill([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'payroll_record_id' => $record->id,
        'work_date' => '2026-07-01',
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'period_classification' => PayrollWorkPeriodClassification::Current,
        'status' => PayrollWorkAllocationStatus::Reserved,
        'contract_id' => $contract->id,
        'basic_daily_rate' => 100,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 100,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 100,
        'active_allocation_key' => 'tampered-key',
    ]);
    $allocation->save();

    expect($allocation->fresh()->active_allocation_key)
        ->toBe(sprintf('%d:%d:%s', $company->id, $employee->id, '2026-07-01'))
        ->not->toBe('tampered-key');
});

test('reverse clears active_allocation_key while reserved keeps server-set key', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'KEY-REV', 100, 0, 0);
    $contract = $employee->fresh()->currentContract;

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $record = PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'draft',
    ]);

    $allocation = PayrollWorkAllocation::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'payroll_record_id' => $record->id,
        'work_date' => '2026-07-02',
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'period_classification' => PayrollWorkPeriodClassification::Current,
        'status' => PayrollWorkAllocationStatus::Reserved,
        'contract_id' => $contract->id,
        'basic_daily_rate' => 100,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 100,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 100,
    ]);

    expect($allocation->active_allocation_key)
        ->toBe(sprintf('%d:%d:%s', $company->id, $employee->id, '2026-07-02'));

    $allocation->update([
        'status' => PayrollWorkAllocationStatus::Reversed,
        'reversed_at' => now(),
        'reversal_reason' => 'test',
    ]);

    expect($allocation->fresh()->active_allocation_key)->toBeNull();
});
