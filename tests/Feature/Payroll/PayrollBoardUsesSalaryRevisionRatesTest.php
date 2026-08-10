<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Inertia\Testing\AssertableInertia as Assert;

test('crew payroll board shows rates from salary revision effective for the period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-BOARD-REV', 50, 300, 50);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 50,
        'site_allowance' => 860,
        'supplementary_allowance' => 100,
    ], '2026-02-01', 'Raised rates');

    $contract->fresh()->update([
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $employee->id)
            ->where('rows.0.contract.basic_salary', fn ($value) => (float) $value === 50.0)
            ->where('rows.0.contract.site_allowance', fn ($value) => (float) $value === 860.0)
            ->where('rows.0.contract.supplementary_allowance', fn ($value) => (float) $value === 100.0));
});

test('office payroll board shows rates from salary revision effective for the period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-BOARD-REV', 9000, 2000, 500, 100);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 9000,
        'housing_allowance' => 2000,
        'transport_allowance' => 500,
        'other_allowances' => 100,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 10000,
        'housing_allowance' => 2500,
        'transport_allowance' => 600,
        'other_allowances' => 150,
    ], '2026-03-01', 'Increment');

    $contract->fresh()->update([
        'basic_salary' => 9000,
        'housing_allowance' => 2000,
        'transport_allowance' => 500,
        'other_allowances' => 100,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Office,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $employee->id)
            ->where('rows.0.contract.basic_salary', fn ($value) => (float) $value === 10000.0)
            ->where('rows.0.contract.housing_allowance', fn ($value) => (float) $value === 2500.0)
            ->where('rows.0.contract.transport_allowance', fn ($value) => (float) $value === 600.0)
            ->where('rows.0.contract.other_allowances', fn ($value) => (float) $value === 150.0));
});

test('office payroll board shows preserved July salary while the current contract shows August salary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-BOARD-JULY', 8000, 2000, 500, 100);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 9000,
        'housing_allowance' => 2500,
        'transport_allowance' => 600,
        'other_allowances' => 150,
    ], '2026-08-01', 'August increase');

    expect((float) $contract->fresh()->basic_salary)->toBe(9000.0);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.contract.basic_salary', fn ($value) => (float) $value === 8000.0)
            ->where('rows.0.contract.housing_allowance', fn ($value) => (float) $value === 2000.0)
            ->where('rows.0.contract.salary_resolution_issue', null));
});

test('monthly crew board shows rates effective for its historical period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view', 'payroll.periods.view']);

    $employee = createCrewMonthlyEmployeeWithContract($company, 'CREW-MONTH-BOARD', 8000, 2000, 500, 100);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 9000,
        'housing_allowance' => 2500,
        'transport_allowance' => 600,
        'other_allowances' => 150,
    ], '2026-08-01', 'August increase');

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'crew_salary_structure' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.salary_structure', 'monthly')
            ->where('rows.0.contract.basic_salary', fn ($value) => (float) $value === 8000.0)
            ->where('rows.0.contract.housing_allowance', fn ($value) => (float) $value === 2000.0));
});

test('payroll board exposes unavailable salary instead of current values when history is uncovered', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-BOARD-MISSING', 9000, 2500, 600, 150);
    $contract = $employee->fresh()->currentContract;
    $revision = ContractSalaryRevision::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);
    ContractSalaryRevisionLine::factory()->create([
        'company_id' => $company->id,
        'revision_id' => $revision->id,
        'component_code' => SalaryComponentCode::Basic,
        'amount' => 9000,
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.contract.basic_salary', null)
            ->where('rows.0.contract.housing_allowance', null)
            ->where('rows.0.contract.salary_resolution_issue.code', 'missing_historical_salary_revision'));
});

test('office payroll board resolves the contract covering the period date', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-BOARD-CONTRACT', 8000, 2000, 0, 0);
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

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.contract.basic_salary', fn ($value) => (float) $value === 8000.0)
            ->where('rows.0.contract.housing_allowance', fn ($value) => (float) $value === 2000.0));
});
