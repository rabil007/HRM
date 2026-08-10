<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\CrewTimesheet;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\GenerateOfficePayroll;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\ResolveOfficeContractForPayrollPeriod;

/**
 * @param  array<string, float>  $amounts
 */
function createUncoveredHistoricalTestRevision(
    EmployeeContract $contract,
    string $effectiveFrom,
    array $amounts,
): ContractSalaryRevision {
    $revision = ContractSalaryRevision::factory()->create([
        'company_id' => $contract->company_id,
        'contract_id' => $contract->id,
        'employee_id' => $contract->employee_id,
        'version' => 1,
        'effective_from' => $effectiveFrom,
    ]);

    foreach ($amounts as $componentCode => $amount) {
        $code = SalaryComponentCode::from($componentCode);

        ContractSalaryRevisionLine::factory()->create([
            'company_id' => $contract->company_id,
            'revision_id' => $revision->id,
            'component_code' => $code,
            'component_name' => $code->label(),
            'rate_type' => $code->defaultRateTypeFor(
                $contract->payroll_category ?? PayrollCategory::Office,
                $contract->resolvedSalaryStructure(),
            ),
            'amount' => $amount,
        ]);
    }

    return $revision;
}

test('office payroll uses preserved July salary and the August revision in their respective periods', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-HIST-1', 8000, 2000, 500, 100);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 9000,
        'housing_allowance' => 2500,
        'transport_allowance' => 600,
        'other_allowances' => 150,
    ], '2026-08-01', 'August increase');

    $july = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $august = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    expect(app(GenerateOfficePayroll::class)->handle($july)->errors)->toBeEmpty()
        ->and(app(GenerateOfficePayroll::class)->handle($august)->errors)->toBeEmpty();

    $julyRecord = PayrollRecord::query()->where('period_id', $july->id)->where('employee_id', $employee->id)->first();
    $augustRecord = PayrollRecord::query()->where('period_id', $august->id)->where('employee_id', $employee->id)->first();

    expect((float) $julyRecord?->basic_salary)->toBe(8000.0)
        ->and((float) $julyRecord?->housing_allowance)->toBe(2000.0)
        ->and((float) $augustRecord?->basic_salary)->toBe(9000.0)
        ->and((float) $augustRecord?->housing_allowance)->toBe(2500.0);
});

test('office payroll blocks when revision history does not cover the period', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-HIST-MISSING', 9000, 2500, 600, 150);
    $contract = $employee->fresh()->currentContract;

    createUncoveredHistoricalTestRevision($contract, '2026-08-01', [
        SalaryComponentCode::Basic->value => 9000,
        SalaryComponentCode::Housing->value => 2500,
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $result = app(GenerateOfficePayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['field'])->toBe('salary_revision')
        ->and($result->errors[0]['message'])->toContain('No salary revision covers 2026-07-01')
        ->and(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});

test('office payroll resolves the historical contract instead of a newer replacement contract', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-CONTRACT-HIST', 8000, 2000, 0, 0);
    $oldContract = $employee->fresh()->currentContract;
    $oldContract->update(['status' => 'ended', 'start_date' => '2024-01-01', 'end_date' => '2026-07-31']);

    $newContract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'start_date' => '2026-08-01',
        'end_date' => null,
        'basic_salary' => 10000,
        'housing_allowance' => 3000,
    ]);
    app(SyncContractSalaryComponentsFromContract::class)->handle($newContract);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $result = app(GenerateOfficePayroll::class)->handle($period);
    $record = PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->first();

    expect($result->errors)->toBeEmpty()
        ->and($employee->fresh()->currentContract?->id)->toBe($newContract->id)
        ->and($record?->contract_id)->toBe($oldContract->id)
        ->and((float) $record?->basic_salary)->toBe(8000.0);
});

test('office payroll blocks ambiguous historical contract overlap', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-CONTRACT-OVERLAP', 8000, 0, 0, 0);
    $employee->fresh()->currentContract?->update([
        'start_date' => '2024-01-01',
        'end_date' => null,
    ]);

    EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'ended',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'basic_salary' => 9000,
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $result = app(GenerateOfficePayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(0)
        ->and($result->errors[0]['message'])->toContain('Multiple Office contracts cover payroll date 2026-07-01')
        ->and(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});

test('historical office contract resolution is isolated to the payroll tenant', function () {
    ['company' => $company] = makePayrollFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-CONTRACT-TENANT', 8000, 0, 0, 0);
    $companyContract = $employee->fresh()->currentContract;

    EmployeeContract::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'start_date' => '2020-01-01',
        'end_date' => null,
        'basic_salary' => 50000,
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $resolved = app(ResolveOfficeContractForPayrollPeriod::class)->resolve($employee, $period);

    expect($resolved['issue'])->toBeNull()
        ->and($resolved['contract']?->id)->toBe($companyContract->id)
        ->and($resolved['contract']?->company_id)->toBe($company->id);
});

test('monthly crew payroll uses historical July salary and August salary revision', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewMonthlyEmployeeWithContract($company, 'CREW-MONTH-HIST', 8000, 2000, 500, 100);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 9000,
        'housing_allowance' => 2500,
        'transport_allowance' => 600,
        'other_allowances' => 150,
    ], '2026-08-01', 'August increase');

    $july = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $august = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    expect(app(GenerateCrewPayroll::class)->handle($july)->errors)->toBeEmpty()
        ->and(app(GenerateCrewPayroll::class)->handle($august)->errors)->toBeEmpty();

    $julyRecord = PayrollRecord::query()->where('period_id', $july->id)->where('employee_id', $employee->id)->first();
    $augustRecord = PayrollRecord::query()->where('period_id', $august->id)->where('employee_id', $employee->id)->first();

    expect((float) $julyRecord?->basic_salary)->toBe(8000.0)
        ->and((float) $augustRecord?->basic_salary)->toBe(9000.0);
});

test('monthly crew payroll blocks an uncovered historical salary period', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewMonthlyEmployeeWithContract($company, 'CREW-MONTH-MISSING', 9000, 2500, 600, 150);
    $contract = $employee->fresh()->currentContract;

    createUncoveredHistoricalTestRevision($contract, '2026-08-01', [
        SalaryComponentCode::Basic->value => 9000,
        SalaryComponentCode::Housing->value => 2500,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['field'])->toBe('salary_revision')
        ->and(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});

test('daily crew overtime-only rows use the payment-period historical rates', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'CREW-OT-HIST', 100, 50, 25);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 120,
        'site_allowance' => 70,
        'supplementary_allowance' => 30,
    ], '2026-08-01', 'August rates');

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'overtime_hours' => 10,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);
    $record = PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->first();

    expect($result->errors)->toBeEmpty()
        ->and((float) $record?->calculation_breakdown['overtime']['daily_onsite_rate'])->toBe(175.0)
        ->and((float) $record?->overtime_pay)->toBeGreaterThan(0);
});

test('daily crew overtime-only rows block when no revision covers the payment period', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = createCrewEmployeeWithContract($company, 'CREW-OT-MISSING', 120, 70, 30);
    $contract = $employee->fresh()->currentContract;

    createUncoveredHistoricalTestRevision($contract, '2026-08-01', [
        SalaryComponentCode::Basic->value => 120,
        SalaryComponentCode::SiteAllowance->value => 70,
        SalaryComponentCode::SupplementaryAllowance->value => 30,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'overtime_hours' => 10,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period);

    expect($result->generatedCount)->toBe(0)
        ->and($result->errors[0]['field'])->toBe('salary_revision')
        ->and(PayrollRecord::query()->where('period_id', $period->id)->exists())->toBeFalse();
});
