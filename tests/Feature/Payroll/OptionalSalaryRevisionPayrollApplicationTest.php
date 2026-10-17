<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\UpdateContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Contracts\AssertContractSalaryRevisionEditable;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('new daily crew salary revision defaults to payroll off over http', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.create']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], createdBy: $user->id);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-10-01',
            'reason' => 'History only',
            'basic_salary' => 250,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $revision = ContractSalaryRevision::query()
        ->where('contract_id', $contract->id)
        ->orderByDesc('version')
        ->first();

    expect($revision)->not->toBeNull()
        ->and($revision->apply_to_payroll)->toBeFalse()
        ->and($revision->payroll_effective_from)->toBeNull();
});

test('new monthly crew salary revision defaults to payroll off over http', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.create']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 875,
    ], createdBy: $user->id);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-10-01',
            'reason' => 'History only',
            'basic_salary' => 16000,
            'housing_allowance' => 10000,
            'transport_allowance' => 5000,
            'other_allowances' => 24125,
            'eos_monthly_amount' => 1000,
        ])
        ->assertRedirect();

    $revision = ContractSalaryRevision::query()
        ->where('contract_id', $contract->id)
        ->orderByDesc('version')
        ->first();

    expect($revision->apply_to_payroll)->toBeFalse()
        ->and($revision->payroll_effective_from)->toBeNull();
});

test('unchecked crew revision is saved but does not affect payroll rates', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 250,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        '2026-10-01',
        'Not for payroll',
        null,
        false,
        null,
    );

    $components = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-10-15'));
    $basic = $components->first(
        fn ($component) => $component->component_code === SalaryComponentCode::Basic,
    );

    expect((float) $basic->amount)->toBe(200.0)
        ->and(ContractSalaryRevision::query()->where('contract_id', $contract->id)->count())->toBe(2);
});

test('checked crew revision requires payroll effective month and rejects earlier payroll month', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.create']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], createdBy: $user->id);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-10-01',
            'apply_to_payroll' => true,
            'reason' => 'Missing payroll month',
            'basic_salary' => 275,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ])
        ->assertSessionHasErrors('payroll_effective_from');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-10-01',
            'apply_to_payroll' => true,
            'payroll_effective_from' => '2026-09-01',
            'reason' => 'Too early',
            'basic_salary' => 275,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ])
        ->assertSessionHasErrors('payroll_effective_from');
});

test('checked crew revision normalizes mid-month payroll dates to month start', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    $revision = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 275,
            'site_allowance' => 60,
            'supplementary_allowance' => 30,
        ],
        '2026-10-15',
        'Normalize',
        null,
        true,
        '2026-10-20',
    );

    expect($revision->effective_from->toDateString())->toBe('2026-10-01')
        ->and($revision->payroll_effective_from->toDateString())->toBe('2026-10-01')
        ->and($revision->apply_to_payroll)->toBeTrue();
});

test('daily crew applies revised rates from payroll effective month only', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['payroll.periods.view', 'payroll.periods.update']);

    $employee = createCrewEmployeeWithContract($company, 'CREW-OPT-1', 200, 50, 25);
    $contract = $employee->fresh()->currentContract;

    app(ApplyContractSalaryRevision::class)->handle($contract, [
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-01-01', 'Initial');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 250,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-09-01', 'History only', null, false, null);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 275,
        'site_allowance' => 70,
        'supplementary_allowance' => 35,
    ], '2026-10-01', 'Payroll on', null, true, '2026-10-01');

    $september = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $september->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 10,
        'overtime_hours' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $september))
        ->assertRedirect();

    $septemberRecord = PayrollRecord::query()
        ->where('period_id', $september->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect((float) $septemberRecord->calculation_breakdown['rates']['basic_daily'])->toBe(200.0)
        ->and((float) $septemberRecord->calculation_breakdown['rates']['site_allowance_daily'])->toBe(50.0);

    $october = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
    ]);
    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $october->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 10,
        'overtime_hours' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $october))
        ->assertRedirect();

    $octoberRecord = PayrollRecord::query()
        ->where('period_id', $october->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect((float) $octoberRecord->calculation_breakdown['rates']['basic_daily'])->toBe(275.0)
        ->and((float) $octoberRecord->calculation_breakdown['rates']['site_allowance_daily'])->toBe(70.0)
        ->and((float) $octoberRecord->calculation_breakdown['rates']['supplementary_allowance_daily'])->toBe(35.0);
});

test('monthly crew payroll package and eos follow payroll-applicable revision exactly once', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 875,
    ]);

    $unapplied = app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 16000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 1000,
    ], '2026-10-01', 'History only', null, false, null);

    $september = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $september->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);
    expect(app(GenerateCrewPayroll::class)->handle($september)->generatedCount)->toBe(1);
    $septemberRecord = PayrollRecord::query()->where('period_id', $september->id)->sole();
    expect((float) $septemberRecord->gross_salary)->toBe(55000.0)
        ->and((float) $septemberRecord->calculation_breakdown['eos_monthly_paid'])->toBe(875.0);

    $octoberWhileUnapplied = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $octoberWhileUnapplied->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);
    expect(app(GenerateCrewPayroll::class)->handle($octoberWhileUnapplied)->generatedCount)->toBe(1);
    $octoberUnappliedRecord = PayrollRecord::query()->where('period_id', $octoberWhileUnapplied->id)->sole();
    expect((float) $octoberUnappliedRecord->gross_salary)->toBe(55000.0)
        ->and((float) $octoberUnappliedRecord->calculation_breakdown['eos_monthly_paid'])->toBe(875.0);

    app(UpdateContractSalaryRevision::class)->handle(
        $contract->fresh(),
        $unapplied,
        [
            'basic_salary' => 16000,
            'housing_allowance' => 10000,
            'transport_allowance' => 5000,
            'other_allowances' => 24125,
            'eos_monthly_amount' => 1000,
        ],
        '2026-10-01',
        'Now apply to payroll',
        true,
        '2026-10-01',
    );

    expect(app(GenerateCrewPayroll::class)->handle($octoberWhileUnapplied->fresh())->generatedCount)->toBe(1);
    $octoberAppliedRecord = PayrollRecord::query()->where('period_id', $octoberWhileUnapplied->id)->sole();
    expect((float) $octoberAppliedRecord->gross_salary)->toBe(56125.0)
        ->and((float) $octoberAppliedRecord->calculation_breakdown['eos_monthly_paid'])->toBe(1000.0);
});

test('later payroll-applicable revision supersedes earlier one and ignores unchecked revisions between them', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 220,
        'site_allowance' => 55,
        'supplementary_allowance' => 25,
    ], '2026-06-01', 'Applied June', null, true, '2026-06-01');

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 999,
        'site_allowance' => 999,
        'supplementary_allowance' => 999,
    ], '2026-08-01', 'Ignored', null, false, null);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 275,
        'site_allowance' => 70,
        'supplementary_allowance' => 35,
    ], '2026-10-01', 'Applied Oct', null, true, '2026-10-01');

    $resolver = app(ResolveEffectiveContractSalaryComponents::class);
    $basic = fn ($asOf) => (float) $resolver->handle($contract->fresh(), Carbon::parse($asOf))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::Basic)
        ->amount;

    expect($basic('2026-07-15'))->toBe(220.0)
        ->and($basic('2026-09-15'))->toBe(220.0)
        ->and($basic('2026-10-01'))->toBe(275.0);
});

test('initial and migrated historical revisions remain payroll applicable', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    $initial = ContractSalaryRevision::query()->where('contract_id', $contract->id)->sole();

    expect($initial->apply_to_payroll)->toBeTrue()
        ->and($initial->payroll_effective_from->toDateString())->toBe('2026-01-01');

    $components = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-05-01'));
    $basic = $components->first(
        fn ($component) => $component->component_code === SalaryComponentCode::Basic,
    );

    expect((float) $basic->amount)->toBe(200.0);
});

test('editing an unapplied revision does not affect payroll rates', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    $revision = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 250,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        '2026-10-01',
        'Unapplied',
        null,
        false,
        null,
    );

    app(UpdateContractSalaryRevision::class)->handle(
        $contract->fresh(),
        $revision,
        [
            'basic_salary' => 999,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        '2026-10-01',
        'Still unapplied',
        false,
        null,
    );

    $basic = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-10-15'))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::Basic);

    expect((float) $basic->amount)->toBe(200.0);
});

test('finalized payroll locks unsafe edits to an applied revision', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    $revision = ContractSalaryRevision::query()->where('contract_id', $contract->id)->sole();

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Approved,
    ]);

    PayrollWorkAllocation::query()->create([
        'company_id' => $company->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-10-02',
        'pay_category' => 'onsite',
        'period_classification' => 'current',
        'status' => PayrollWorkAllocationStatus::Approved,
        'contract_id' => $contract->id,
        'salary_revision_id' => $revision->id,
        'basic_daily_rate' => 200,
        'site_allowance_daily_rate' => 50,
        'supplementary_allowance_daily_rate' => 25,
        'basic_amount' => 200,
        'site_allowance_amount' => 50,
        'supplementary_allowance_amount' => 25,
        'total_amount' => 275,
        'approved_at' => now(),
    ]);

    expect(fn () => app(UpdateContractSalaryRevision::class)->handle(
        $contract->fresh(),
        $revision,
        [
            'basic_salary' => 300,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        '2026-01-01',
        'Unsafe',
        true,
        '2026-01-01',
    ))->toThrow(ValidationException::class, AssertContractSalaryRevisionEditable::REFERENCED_MESSAGE);
});

test('office salary revisions remain payroll applicable by default', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.create']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
        'basic_salary' => 10000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
        'other_allowances' => 500,
    ], createdBy: $user->id);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-10-01',
            'apply_to_payroll' => false,
            'basic_salary' => 12000,
            'housing_allowance' => 2000,
            'transport_allowance' => 1000,
            'other_allowances' => 500,
        ])
        ->assertRedirect();

    $revision = ContractSalaryRevision::query()
        ->where('contract_id', $contract->id)
        ->orderByDesc('version')
        ->first();

    expect($revision->apply_to_payroll)->toBeTrue()
        ->and($revision->payroll_effective_from->toDateString())->toBe('2026-10-01');

    $basic = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-10-01'))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::Basic);

    expect((float) $basic->amount)->toBe(12000.0);
});

test('conflicting payroll effective months are rejected for the same contract', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => ContractSalaryStructure::Daily->value,
        'basic_salary' => 200,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 220,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-09-01', 'First', null, true, '2026-10-01');

    expect(fn () => app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 230,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-10-01', 'Conflict', null, true, '2026-10-01'))
        ->toThrow(ValidationException::class);
});
