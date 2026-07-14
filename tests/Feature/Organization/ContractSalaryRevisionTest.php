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
use Inertia\Testing\AssertableInertia as Assert;

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

test('authorized user can update a salary revision', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.manage']);

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

    $revision = ContractSalaryRevision::query()->where('contract_id', $contract->id)->first();
    expect($revision)->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('organization.employees.contracts.salary-revisions.update', [
            'employee' => $employee,
            'employeeContract' => $contract,
            'salaryRevision' => $revision,
        ]), [
            'effective_from' => '2026-01-15',
            'reason' => 'Corrected rates',
            'basic_salary' => 110,
            'site_allowance' => 60,
            'supplementary_allowance' => 30,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $revision->refresh();
    expect($revision->effective_from->toDateString())->toBe('2026-01-15')
        ->and($revision->reason)->toBe('Corrected rates')
        ->and((float) $contract->fresh()->basic_salary)->toBe(110.0)
        ->and((float) $contract->fresh()->site_allowance)->toBe(60.0);
});

test('authorized user can delete a salary revision when more than one exists', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.salary_revisions.manage']);

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

    $second = app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 120,
            'site_allowance' => 80,
            'supplementary_allowance' => 40,
        ],
        '2026-03-01',
        'Increase',
        $user->id,
    );

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.employees.contracts.salary-revisions.destroy', [
            'employee' => $employee,
            'employeeContract' => $contract,
            'salaryRevision' => $second,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ContractSalaryRevision::query()->where('contract_id', $contract->id)->count())->toBe(1)
        ->and((float) $contract->fresh()->basic_salary)->toBe(100.0)
        ->and((float) $contract->fresh()->site_allowance)->toBe(50.0);
});

test('employee profile exposes salary revisions tab and revision data', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'contracts.view',
        'contracts.salary_revisions.manage',
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
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('employee_tabs.salary_revisions', true)
            ->where('can.contracts_salary_revisions_manage', true)
            ->tap(fn (Assert $page) => assertEmployeeProfileRecords(
                $page,
                function (Assert $deferred) use ($contract): void {
                    $deferred->has('contracts')
                        ->where('contracts', fn ($contracts) => collect($contracts)->contains(
                            fn ($row) => ($row['id'] ?? null) === $contract->id
                                && count($row['salary_revisions'] ?? []) === 1
                                && ($row['salary_revisions'][0]['version'] ?? null) === 1,
                        ));
                },
            )));
});
