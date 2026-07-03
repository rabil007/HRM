<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\Company;
use App\Models\ContractSalaryComponent;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage employee contracts', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertRedirect(route('login'));
});

test('users without permission cannot manage employee contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECT',
        'name' => 'Contract Testland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECT',
        'name' => 'Contract Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Co',
        'slug' => 'contract-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-C01',
            'name' => 'Contract Worker',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertForbidden();
});

test('employee show page includes contract count but not contracts tab data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECS',
        'name' => 'Contract Show Land',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECS',
        'name' => 'Contract Show Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Show Co',
        'slug' => 'contract-show-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'employee_no' => 'EMP-C02',
        'name' => 'Show Contracts',
        'status' => 'active',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'limited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'labor_contract_id' => 'LC-100',
        'status' => 'ended',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'contracts.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('contract_count', 1)
            ->where('employee_tabs.contract', false)
            ->missing('contracts')
            ->where('can.contracts_view', true));
});

test('users with permission can add update and delete contracts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECM',
        'name' => 'Contract Manage Land',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECM',
        'name' => 'Contract Manage Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Manage Co',
        'slug' => 'contract-manage-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-C03',
            'name' => 'Manage Contracts',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'contracts.create',
        'contracts.update',
        'contracts.delete',
    ]);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2024-06-01',
        'status' => 'ended',
        'basic_salary' => 5000,
    ])->assertRedirect();

    $ended = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'ended')
        ->latest('id')
        ->first();
    expect($ended)->not->toBeNull();

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'limited',
        'start_date' => '2026-01-01',
        'end_date' => '2027-01-01',
        'status' => 'active',
        'basic_salary' => 8000,
        'note' => 'Renewal after probation completion.',
    ])->assertRedirect();

    $active = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($active)->not->toBeNull()
        ->and($active->contract_type)->toBe('limited')
        ->and($active->note)->toBe('Renewal after probation completion.');

    $this->put(route('organization.employees.contracts.update', [$employee, $active]), [
        'contract_type' => 'limited',
        'start_date' => '2026-01-01',
        'end_date' => '2028-01-01',
        'status' => 'active',
        'basic_salary' => 9000,
        'note' => 'Salary adjustment and contract extension.',
    ])->assertRedirect();

    expect($active->fresh()->basic_salary)->toBe('9000.00')
        ->and($active->fresh()->note)->toBe('Salary adjustment and contract extension.');

    $this->delete(route('organization.employees.contracts.destroy', [$employee, $active]))
        ->assertRedirect();

    $this->assertSoftDeleted('employee_contracts', ['id' => $active->id]);
});

test('activating a contract ends other active contracts for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ECA',
        'name' => 'Contract Active Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECA',
        'name' => 'Contract Active Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Active Co',
        'slug' => 'contract-active-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-C04',
            'name' => 'Active Contract',
            'status' => 'active',
        ]);

    $first = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2025-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'limited',
        'start_date' => '2026-05-01',
        'status' => 'active',
    ])->assertRedirect();

    expect($first->fresh()->status)->toBe('ended');
});

test('contract store rejects invalid salary and end date with validation errors', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CVE',
        'name' => 'Contract Validation Land',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVE',
        'name' => 'Contract Validation Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Validation Co',
        'slug' => 'contract-validation-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-CVE-1',
            'name' => 'Validation Contracts',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->from(route('organization.employees.show', $employee))
        ->post(route('organization.employees.contracts.store', $employee), [
            'contract_type' => 'limited',
            'start_date' => '2026-06-01',
            'end_date' => '2026-01-01',
            'status' => 'active',
            'basic_salary' => '5,000',
        ])
        ->assertSessionHasErrors(['end_date', 'basic_salary']);
});

test('contract store persists supplementary and site allowances', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CWA',
        'name' => 'Crew Allowance Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CWA',
        'name' => 'Crew Allowance Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Allowance Co',
        'slug' => 'crew-allowance-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-CWA-1',
            'name' => 'Crew Member',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 50,
        'supplementary_allowance' => 428,
        'site_allowance' => 715,
    ])->assertRedirect();

    $contract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($contract)->not->toBeNull()
        ->and($contract->basic_salary)->toBe('50.00')
        ->and($contract->supplementary_allowance)->toBe('428.00')
        ->and($contract->site_allowance)->toBe('715.00');
});

test('contract store persists payroll_category correctly')
    ->with([
        'office category' => [PayrollCategory::Office],
        'crew category' => [PayrollCategory::Crew],
    ])
    ->expect(function (PayrollCategory $category) {
        $user = User::factory()->create();
        $this->actingAs($user);

        $country = Country::query()->create([
            'code' => 'PC'.strtoupper($category->value),
            'name' => "Payroll Category {$category->value} Land",
            'dial_code' => '+900',
            'is_active' => true,
        ]);

        $currency = Currency::query()->create([
            'code' => 'PC'.strtoupper($category->value),
            'name' => "Payroll Cat {$category->value} Currency",
            'symbol' => '$',
            'is_active' => true,
        ]);

        $company = Company::query()->create([
            'name' => "Payroll Cat {$category->value} Co",
            'slug' => "payroll-cat-{$category->value}-co",
            'working_days' => [1, 2, 3, 4, 5],
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'timezone' => 'Asia/Dubai',
            'payroll_cycle' => 'monthly',
            'status' => 'active',
        ]);

        $employee = Employee::factory()->forCompany($company)->create([
            'employee_no' => 'EMP-PC-'.strtoupper($category->value),
            'status' => 'active',
        ]);

        grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

        $this->post(route('organization.employees.contracts.store', $employee), [
            'contract_type' => 'unlimited',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => $category->value,
            'basic_salary' => 5000,
        ])->assertRedirect();

        $contract = EmployeeContract::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        expect($contract)->not->toBeNull()
            ->and($contract->payroll_category)->toBe($category);
    });

test('contract store syncs salary components from legacy columns', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CSC',
        'name' => 'Component Sync Country',
        'dial_code' => '+901',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CSC',
        'name' => 'Component Sync Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Component Sync Co',
        'slug' => 'component-sync-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-CSC-1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
        'basic_salary' => 8000,
        'housing_allowance' => 1500,
    ])->assertRedirect();

    $contract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    $components = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->get();

    expect($components)->toHaveCount(2)
        ->and($components->firstWhere('component_code', SalaryComponentCode::Basic)?->amount)->toBe('8000.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Housing)?->amount)->toBe('1500.00');
});
