<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;

test('creating a contract with salary creates version 1 revision', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'contracts.create',
        'contracts.view',
        'contracts.salary_revisions.manage',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.store', $employee), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 100,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ])
        ->assertRedirect();

    $contract = EmployeeContract::query()->where('employee_id', $employee->id)->first();
    expect($contract)->not->toBeNull();

    $revision = ContractSalaryRevision::query()->where('contract_id', $contract->id)->first();
    expect($revision)->not->toBeNull()
        ->and($revision->version)->toBe(1)
        ->and($revision->effective_from->toDateString())->toBe('2026-01-01')
        ->and($revision->lines)->toHaveCount(3);
});

test('storing a salary revision creates version 2 and updates contract components', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'contracts.salary_revisions.manage',
        'contracts.view',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle(
        $company->id,
        $employee,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 100,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        createdBy: $user->id,
    );

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-03-01',
            'reason' => 'Rate increase',
            'basic_salary' => 120,
            'site_allowance' => 75,
            'supplementary_allowance' => 40,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ContractSalaryRevision::query()->where('contract_id', $contract->id)->count())->toBe(2);

    $contract->refresh();
    expect((float) $contract->basic_salary)->toBe(120.0)
        ->and((float) $contract->site_allowance)->toBe(75.0)
        ->and((float) $contract->supplementary_allowance)->toBe(40.0);

    $siteComponent = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->where('component_code', SalaryComponentCode::SiteAllowance)
        ->where('status', SalaryComponentStatus::Active)
        ->first();

    expect($siteComponent)->not->toBeNull()
        ->and((float) $siteComponent->amount)->toBe(75.0);
});

test('users without salary revision permission cannot store revisions', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.update']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 5000,
        'status' => 'active',
    ]);

    app(ApplyContractSalaryRevision::class)->handle(
        $contract,
        ['basic_salary' => 5000],
        '2026-01-01',
        'Initial',
        null,
    );

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.salary-revisions.store', [
            'employee' => $employee,
            'employeeContract' => $contract,
        ]), [
            'effective_from' => '2026-04-01',
            'basic_salary' => 6000,
        ])
        ->assertForbidden();
});
