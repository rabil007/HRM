<?php

use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;

test('crew payroll generation uses revised rates from effective date', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-REV-1', 100, 50, 25);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 100,
        'site_allowance' => 80,
        'supplementary_allowance' => 40,
    ], '2026-06-01', 'June rates');

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 10,
        'overtime_hours' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->calculation_breakdown['rates']['site_allowance_daily'])->toBe(80.0)
        ->and((float) $record->calculation_breakdown['rates']['supplementary_allowance_daily'])->toBe(40.0)
        ->and((float) $record->calculation_breakdown['lines']['site_allowance'])->toBe(800.0);
});

test('crew payroll generation for earlier period keeps prior revision rates', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-REV-2', 100, 50, 25);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 100,
        'site_allowance' => 80,
        'supplementary_allowance' => 40,
    ], '2026-06-01', 'June rates');

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 10,
        'overtime_hours' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->calculation_breakdown['rates']['site_allowance_daily'])->toBe(50.0)
        ->and((float) $record->calculation_breakdown['lines']['site_allowance'])->toBe(500.0);
});

test('office payroll generation uses incremented basic from salary revision', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-REV-1', 10000, 2000, 1000, 500);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 10000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
        'other_allowances' => 500,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 12000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
        'other_allowances' => 500,
    ], '2026-06-01', 'Increment');

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBe(12000.0)
        ->and((float) $record->housing_allowance)->toBe(2000.0);
});
