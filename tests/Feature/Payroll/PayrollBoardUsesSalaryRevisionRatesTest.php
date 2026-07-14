<?php

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
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
