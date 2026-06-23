<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Inertia\Testing\AssertableInertia as Assert;

test('users without permission cannot view payroll records index', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.records.index'))
        ->assertForbidden();
});

test('payroll records index lists company records with filters', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $officePeriod = PayrollPeriod::factory()->for($company)->office()->create([
        'name' => 'Office June',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    $crewPeriod = PayrollPeriod::factory()->for($company)->create([
        'name' => 'Crew June',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Office Worker',
        'employee_no' => 'OFF-900',
    ]);
    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => 5000,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($officeEmployee->currentContract);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Crew Worker',
        'employee_no' => 'CRW-900',
    ]);
    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $officeEmployee->id,
        'period_id' => $officePeriod->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 5000,
        'net_salary' => 5000,
    ]);
    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $crewEmployee->id,
        'period_id' => $crewPeriod->id,
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 3000,
        'net_salary' => 2800,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.records.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/records')
            ->has('records', 2));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.records.index', ['category' => 'office']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('records', 1)
            ->where('records.0.payroll_category', 'office')
            ->where('records.0.employee.name', 'Office Worker'));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.records.index', ['search' => 'CRW-900']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('records', 1)
            ->where('records.0.employee.employee_no', 'CRW-900'));
});

test('payroll records index does not leak records from other companies', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $period = PayrollPeriod::factory()->for($otherCompany)->create();
    $employee = Employee::factory()->forCompany($otherCompany)->create();

    PayrollRecord::factory()->for($otherCompany)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.records.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('records', 0));
});
