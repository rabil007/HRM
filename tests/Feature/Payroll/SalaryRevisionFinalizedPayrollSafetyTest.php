<?php

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
use App\Support\Contracts\Actions\DeleteContractSalaryRevision;
use App\Support\Contracts\Actions\UpdateContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Contracts\AssertContractSalaryRevisionEditable;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\AssertMonthlyCrewSalaryStableAcrossPeriod;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('enabling an unchecked revision for a finalized period is rejected', function () {
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

    $unapplied = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 275,
            'site_allowance' => 70,
            'supplementary_allowance' => 35,
        ],
        '2026-09-01',
        'History only',
        null,
        false,
        null,
    );

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Approved,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'status' => 'approved',
    ]);

    expect(fn () => app(UpdateContractSalaryRevision::class)->handle(
        $contract->fresh(),
        $unapplied,
        [
            'basic_salary' => 275,
            'site_allowance' => 70,
            'supplementary_allowance' => 35,
        ],
        '2026-09-01',
        'Enable payroll',
        true,
        '2026-09-01',
    ))->toThrow(ValidationException::class, AssertContractSalaryRevisionEditable::LOCKED_MESSAGE);
});

test('new backdated payroll-applicable revision affecting paid payroll is rejected', function () {
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

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Paid,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'status' => 'paid',
    ]);

    expect(fn () => app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 275,
            'site_allowance' => 70,
            'supplementary_allowance' => 35,
        ],
        '2026-10-01',
        'Backdated',
        null,
        true,
        '2026-10-01',
    ))->toThrow(ValidationException::class, AssertContractSalaryRevisionEditable::LOCKED_MESSAGE);
});

test('future payroll-applicable revision with no finalized impact is allowed', function () {
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

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Paid,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'status' => 'paid',
    ]);

    $revision = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 275,
            'site_allowance' => 70,
            'supplementary_allowance' => 35,
        ],
        '2026-11-01',
        'Future',
        null,
        true,
        '2026-11-01',
    );

    expect($revision->apply_to_payroll)->toBeTrue()
        ->and($revision->payroll_effective_from->toDateString())->toBe('2026-11-01');
});

test('deleting a later applicable revision that would alter finalized rates is rejected', function () {
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

    $later = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 275,
            'site_allowance' => 70,
            'supplementary_allowance' => 35,
        ],
        '2026-10-01',
        'October package',
        null,
        true,
        '2026-10-01',
    );

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Approved,
    ]);
    PayrollWorkAllocation::query()->create([
        'company_id' => $company->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-10-05',
        'pay_category' => 'onsite',
        'period_classification' => 'current',
        'status' => PayrollWorkAllocationStatus::Approved,
        'contract_id' => $contract->id,
        'salary_revision_id' => $later->id,
        'basic_daily_rate' => 275,
        'site_allowance_daily_rate' => 70,
        'supplementary_allowance_daily_rate' => 35,
        'basic_amount' => 275,
        'site_allowance_amount' => 70,
        'supplementary_allowance_amount' => 35,
        'total_amount' => 380,
        'approved_at' => now(),
    ]);

    expect(fn () => app(DeleteContractSalaryRevision::class)->handle($contract->fresh(), $later))
        ->toThrow(ValidationException::class, AssertContractSalaryRevisionEditable::LOCKED_MESSAGE);
});

test('editing existing crew contract salary defaults to payroll off', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.update', 'contracts.view']);

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
        ->put(route('organization.employees.contracts.update', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => 'crew',
            'salary_structure' => 'daily',
            'basic_salary' => 250,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
            'revision_effective_from' => '2026-10-01',
            'revision_apply_to_payroll' => false,
        ])
        ->assertRedirect();

    $revision = ContractSalaryRevision::query()
        ->where('contract_id', $contract->id)
        ->orderByDesc('version')
        ->first();

    expect($revision->apply_to_payroll)->toBeFalse()
        ->and((float) $contract->fresh()->basic_salary)->toBe(250.0);

    $basic = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-10-15'))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::Basic);

    expect((float) $basic->amount)->toBe(200.0);
});

test('crew contract edit can enable payroll application from selected month', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.update', 'contracts.view']);

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
        ->put(route('organization.employees.contracts.update', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => 'crew',
            'salary_structure' => 'daily',
            'basic_salary' => 250,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
            'revision_effective_from' => '2026-10-01',
            'revision_apply_to_payroll' => true,
            'revision_payroll_effective_from' => '2026-10-01',
        ])
        ->assertRedirect();

    $basic = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh(), Carbon::parse('2026-10-01'))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::Basic);

    expect((float) $basic->amount)->toBe(250.0);
});

test('monthly crew off-cycle period crossing a salary revision boundary is blocked', function () {
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

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 16000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 1000,
    ], '2026-11-01', 'November package', null, true, '2026-11-01');

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-10-15',
        'end_date' => '2026-11-14',
        'status' => PayrollPeriodStatus::Draft,
        'regular_period_key' => null,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period))
        ->toThrow(ValidationException::class);

    expect(fn () => app(AssertMonthlyCrewSalaryStableAcrossPeriod::class)->assert($contract->fresh(), $period))
        ->toThrow(ValidationException::class, AssertMonthlyCrewSalaryStableAcrossPeriod::BOUNDARY_MESSAGE);
});

test('monthly crew off-cycle period without a rate change remains supported', function () {
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

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-10-15',
        'end_date' => '2026-11-14',
        'status' => PayrollPeriodStatus::Draft,
        'regular_period_key' => null,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);

    expect(app(GenerateCrewPayroll::class)->handle($period)->generatedCount)->toBe(1);
});

test('full calendar month monthly crew payroll still succeeds across a month boundary revision', function () {
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

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 16000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 1000,
    ], '2026-11-01', 'November package', null, true, '2026-11-01');

    $october = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $october->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);
    expect(app(GenerateCrewPayroll::class)->handle($october)->generatedCount)->toBe(1);
    expect((float) PayrollRecord::query()->where('period_id', $october->id)->value('gross_salary'))->toBe(55000.0);

    $november = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    CrewTimesheet::factory()->for($company)->create([
        'period_id' => $november->id,
        'employee_id' => $employee->id,
        'unpaid_leave_days' => 0,
    ]);
    expect(app(GenerateCrewPayroll::class)->handle($november)->generatedCount)->toBe(1);
    expect((float) PayrollRecord::query()->where('period_id', $november->id)->value('gross_salary'))->toBe(56125.0);
});
