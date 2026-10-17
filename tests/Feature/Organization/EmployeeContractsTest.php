<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\Company;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\User;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use App\Support\Contracts\AssertContractSalaryStructureChangeAllowed;
use App\Support\Contracts\ContractSalaryTotals;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage employee contracts', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.contracts.store', $employee), [
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
        ->withoutDefaultContract()
        ->create([
            'employee_no' => 'EMP-C01',
            'name' => 'Contract Worker',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
    ])->assertForbidden();
});

test('employee show page includes contracts tab data when user can view contracts', function () {
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
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'labor_contract_id' => 'LC-100',
        'status' => 'ended',
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'contracts.view',
        'contracts.salary_revisions.view',
    ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('contract_count', 1)
            ->where('employee_tabs.contract', true)
            ->where('employee_tabs.salary_revisions', true)
            ->where('can.contracts_view', true)
            ->where('can.contracts_salary_revisions_view', true)
            ->tap(fn (Assert $page) => assertEmployeeProfileRecords(
                $page,
                fn (Assert $deferred) => $deferred
                    ->has('contracts', 1)
                    ->where('contracts.0.salary_structure', 'daily'),
            )));
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
        ->withoutDefaultContract()
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
        ->and($active->note)->toBe('Renewal after probation completion.');

    $this->put(route('organization.employees.contracts.update', [$employee, $active]), [
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
        ->withoutDefaultContract()
        ->create([
            'employee_no' => 'EMP-C04',
            'name' => 'Active Contract',
            'status' => 'active',
        ]);

    $first = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2025-01-01',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
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
        ->withoutDefaultContract()
        ->create([
            'employee_no' => 'EMP-CVE-1',
            'name' => 'Validation Contracts',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->from(route('organization.employees.show', $employee))
        ->post(route('organization.employees.contracts.store', $employee), [
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
        ->withoutDefaultContract()
        ->create([
            'employee_no' => 'EMP-CWA-1',
            'name' => 'Crew Member',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
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

        $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
            'employee_no' => 'EMP-PC-'.strtoupper($category->value),
            'status' => 'active',
        ]);

        grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

        $this->post(route('organization.employees.contracts.store', $employee), [
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

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'employee_no' => 'EMP-CSC-1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
        'basic_salary' => 8000,
        'housing_allowance' => 1500,
    ])->assertRedirect();

    $contract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->latest('id')
        ->first();

    expect($contract)->not->toBeNull();

    $components = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->get();

    expect($components)->toHaveCount(2)
        ->and($components->firstWhere('component_code', SalaryComponentCode::Basic)?->amount)->toBe('8000.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Housing)?->amount)->toBe('1500.00');
});

test('contract form rejects EOS provision unless the contract is monthly crew', function (array $payload) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.create']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('organization.employees.show', $employee))
        ->post(route('organization.employees.contracts.store', $employee), array_merge([
            'start_date' => '2026-01-01',
            'status' => 'active',
            'basic_salary' => 1000,
            'eos_monthly_amount' => 800,
        ], $payload))
        ->assertRedirect()
        ->assertSessionHasErrors([
            'eos_monthly_amount' => 'EOS provision is only available for Monthly Crew contracts.',
        ]);

    expect(EmployeeContract::query()->where('employee_id', $employee->id)->count())->toBe(0);
})->with([
    'daily crew' => [[
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
    ]],
    'crew without an explicit structure' => [[
        'payroll_category' => PayrollCategory::Crew->value,
    ]],
    'office' => [[
        'payroll_category' => PayrollCategory::Office->value,
        'salary_structure' => 'monthly',
    ]],
]);

test('monthly crew contract form stores payable EOS in totals and keeps it when a later update omits the field', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.create', 'contracts.update']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.store', $employee), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 4000,
            'housing_allowance' => 500,
            'transport_allowance' => 100,
            'other_allowances' => 50,
            'eos_monthly_amount' => 800,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract = EmployeeContract::query()->where('employee_id', $employee->id)->first();
    expect($contract)->not->toBeNull()
        ->and((float) $contract->eos_monthly_amount)->toBe(800.0)
        ->and(ContractSalaryTotals::total($contract))->toBe(5450.0)
        ->and($contract->supplementary_allowance)->toBeNull()
        ->and($contract->site_allowance)->toBeNull();

    $eosComponent = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->where('component_code', SalaryComponentCode::EosProvision)
        ->first();
    expect($eosComponent)->not->toBeNull()
        ->and($eosComponent->status)->toBe(SalaryComponentStatus::Active)
        ->and((float) $eosComponent->amount)->toBe(800.0);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('organization.employees.contracts.update', [$employee, $contract]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 4200,
            'housing_allowance' => 500,
            'transport_allowance' => 100,
            'other_allowances' => 50,
            'revision_effective_from' => '2026-04-01',
            'revision_reason' => 'Basic increase',
            'revision_apply_to_payroll' => true,
            'revision_payroll_effective_from' => '2026-04-01',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract->refresh();
    expect((float) $contract->basic_salary)->toBe(4200.0)
        ->and((float) $contract->eos_monthly_amount)->toBe(800.0)
        ->and(ContractSalaryTotals::total($contract))->toBe(5650.0);

    $laterRevision = ContractSalaryRevision::query()
        ->where('contract_id', $contract->id)
        ->whereDate('effective_from', '2026-04-01')
        ->first();
    expect($laterRevision)->not->toBeNull()
        ->and($laterRevision->apply_to_payroll)->toBeTrue()
        ->and($laterRevision->lines->first(
            fn ($line) => $line->component_code === SalaryComponentCode::EosProvision,
        )?->amount)->toBe('800.00');
});

test('switching monthly crew to daily is rejected when salary revision history already exists', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.create', 'contracts.update']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.store', $employee), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 4000,
            'eos_monthly_amount' => 800,
        ])
        ->assertRedirect();

    $contract = EmployeeContract::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.contracts.update', [$employee, $contract]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 90,
            'site_allowance' => 10,
            'supplementary_allowance' => 5,
            'eos_monthly_amount' => 800,
            'revision_effective_from' => '2026-06-01',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'eos_monthly_amount' => 'EOS provision is only available for Monthly Crew contracts.',
        ]);

    expect($contract->fresh()->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and((float) $contract->fresh()->eos_monthly_amount)->toBe(800.0)
        ->and(ContractSalaryRevision::query()->where('contract_id', $contract->id)->count())->toBe(1);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.contracts.update', [$employee, $contract]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 90,
            'site_allowance' => 10,
            'supplementary_allowance' => 5,
            'revision_effective_from' => '2026-06-01',
            'revision_reason' => 'Moved to daily rates',
            'revision_apply_to_payroll' => true,
            'revision_payroll_effective_from' => '2026-06-01',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'salary_structure' => AssertContractSalaryStructureChangeAllowed::REVISION_HISTORY_MESSAGE,
        ]);

    $contract->refresh();
    expect($contract->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and((float) $contract->eos_monthly_amount)->toBe(800.0)
        ->and(ContractSalaryRevision::query()->where('contract_id', $contract->id)->count())->toBe(1);

    $historicalEos = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract, Carbon::parse('2026-02-01'))
        ->first(fn ($component) => $component->component_code === SalaryComponentCode::EosProvision);

    expect($historicalEos)->not->toBeNull()
        ->and((float) $historicalEos->amount)->toBe(800.0);
});

test('metadata-only contract updates remain allowed without changing salary structure', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['contracts.create', 'contracts.update']);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.employees.contracts.store', $employee), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 4000,
            'eos_monthly_amount' => 800,
            'note' => 'Initial',
        ])
        ->assertRedirect();

    $contract = EmployeeContract::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('organization.employees.contracts.update', [$employee, $contract]), [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 4000,
            'eos_monthly_amount' => 800,
            'note' => 'Updated note only',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($contract->fresh()->note)->toBe('Updated note only')
        ->and($contract->fresh()->resolvedSalaryStructure()->value)->toBe('monthly');
});

test('monthly crew contract with approved payroll cannot change to daily structure', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 2000,
        'eos_monthly_amount' => 500,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => PayrollPeriodStatus::Approved,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
        'gross_salary' => 17500,
        'net_salary' => 17500,
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 200,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
        revisionApplyToPayroll: true,
        revisionPayrollEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::PAYROLL_HISTORY_MESSAGE,
    );

    expect($contract->fresh()->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and((float) $contract->fresh()->basic_salary)->toBe(15000.0);
});

test('daily crew contract with paid work allocations cannot change to monthly structure', function () {
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => PayrollPeriodStatus::Paid,
    ]);
    PayrollWorkAllocation::query()->create([
        'company_id' => $company->id,
        'payroll_period_id' => $period->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-01-05',
        'pay_category' => 'onsite',
        'period_classification' => 'current',
        'status' => PayrollWorkAllocationStatus::Paid,
        'contract_id' => $contract->id,
        'salary_revision_id' => $contract->salaryRevisions()->value('id'),
        'basic_daily_rate' => 200,
        'site_allowance_daily_rate' => 50,
        'supplementary_allowance_daily_rate' => 25,
        'basic_amount' => 200,
        'site_allowance_amount' => 50,
        'supplementary_allowance_amount' => 25,
        'total_amount' => 275,
        'paid_at' => now(),
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
        revisionApplyToPayroll: true,
        revisionPayrollEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::PAYROLL_HISTORY_MESSAGE,
    );

    expect($contract->fresh()->resolvedSalaryStructure()->value)->toBe('daily');
});

test('legacy monthly crew contract without history may correct salary structure before payroll starts', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-10-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'monthly',
        'basic_salary' => 5000,
        'housing_allowance' => 1000,
        'eos_monthly_amount' => 300,
    ]);

    expect($contract->salaryRevisions()->exists())->toBeFalse();

    $updated = app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 200,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        $contract,
        revisionApplyToPayroll: true,
        revisionEffectiveFrom: '2026-10-01',
        revisionPayrollEffectiveFrom: '2026-10-01',
    );

    expect($updated->resolvedSalaryStructure()->value)->toBe('daily')
        ->and((float) $updated->basic_salary)->toBe(200.0)
        ->and($updated->eos_monthly_amount)->toBeNull()
        ->and($updated->salaryRevisions()->count())->toBe(1)
        ->and($updated->salaryRevisions()->first()->apply_to_payroll)->toBeTrue();
});

test('monthly crew to office is rejected even when both resolve to monthly structure', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 2000,
        'eos_monthly_amount' => 500,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => PayrollPeriodStatus::Approved,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
        'gross_salary' => 17500,
        'net_salary' => 17500,
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Office->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::PAYROLL_HISTORY_MESSAGE,
    );

    $fresh = $contract->fresh();
    expect($fresh->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($fresh->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and((float) $fresh->basic_salary)->toBe(15000.0)
        ->and((float) $fresh->eos_monthly_amount)->toBe(500.0);
});

test('office to monthly crew is rejected when historical office payroll exists', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
        'basic_salary' => 8000,
        'housing_allowance' => 2000,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Office,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => PayrollPeriodStatus::Processing,
    ]);
    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'draft',
        'gross_salary' => 10000,
        'net_salary' => 10000,
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 8000,
            'housing_allowance' => 2000,
            'eos_monthly_amount' => 300,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::PAYROLL_HISTORY_MESSAGE,
    );

    expect($contract->fresh()->payroll_category)->toBe(PayrollCategory::Office);
});

test('monthly crew to office is rejected when only salary revision history exists', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'eos_monthly_amount' => 500,
    ]);

    expect($contract->salaryRevisions()->count())->toBe(1);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Office->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::REVISION_HISTORY_MESSAGE,
    );

    expect($contract->fresh()->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($contract->salaryRevisions()->count())->toBe(1);
});

test('ordinary monthly crew salary revisions within the same category remain allowed', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = app(UpsertEmployeeContract::class)->handle($company->id, $employee, [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 2000,
        'eos_monthly_amount' => 500,
    ]);

    $updated = app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 16000,
            'housing_allowance' => 2000,
            'eos_monthly_amount' => 500,
        ],
        $contract->fresh(),
        revisionEffectiveFrom: '2026-10-01',
        revisionApplyToPayroll: true,
        revisionPayrollEffectiveFrom: '2026-10-01',
    );

    expect($updated->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($updated->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and((float) $updated->basic_salary)->toBe(16000.0)
        ->and($updated->salaryRevisions()->count())->toBe(2);
});

test('later-month legacy structure and category transitions are rejected without history', function (array $from, array $to) {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
        ...$from,
    ]);

    expect($contract->salaryRevisions()->exists())->toBeFalse();

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            ...$to,
        ],
        $contract,
        revisionEffectiveFrom: '2026-10-01',
        revisionApplyToPayroll: true,
        revisionPayrollEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::LATER_MONTH_MESSAGE,
    );

    $fresh = $contract->fresh();
    expect($fresh->payroll_category->value)->toBe($from['payroll_category'] instanceof PayrollCategory
        ? $from['payroll_category']->value
        : $from['payroll_category'])
        ->and($fresh->salaryRevisions()->count())->toBe(0);
})->with([
    'monthly crew to daily' => [
        [
            'payroll_category' => PayrollCategory::Crew,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
            'eos_monthly_amount' => 500,
        ],
        [
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 200,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
    ],
    'daily crew to monthly' => [
        [
            'payroll_category' => PayrollCategory::Crew,
            'salary_structure' => 'daily',
            'basic_salary' => 200,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        [
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
        ],
    ],
    'monthly crew to office' => [
        [
            'payroll_category' => PayrollCategory::Crew,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
            'eos_monthly_amount' => 500,
        ],
        [
            'payroll_category' => PayrollCategory::Office->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
        ],
    ],
]);

test('ambiguous category or structure change without revision effective month is rejected', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);
    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-10-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'monthly',
        'basic_salary' => 5000,
        'housing_allowance' => 1000,
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 200,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
        $contract,
        // No revisionEffectiveFrom — cannot prove this is only an initial correction.
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::AMBIGUOUS_EFFECTIVE_MONTH_MESSAGE,
    );

    expect($contract->fresh()->resolvedSalaryStructure()->value)->toBe('monthly')
        ->and($contract->fresh()->salaryRevisions()->count())->toBe(0);
});

test('rejected classification change does not end sibling active contracts', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $older = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2025-01-01',
        'end_date' => null,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'daily',
        'basic_salary' => 180,
        'site_allowance' => 40,
        'supplementary_allowance' => 20,
    ]);

    $current = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'monthly',
        'basic_salary' => 15000,
        'housing_allowance' => 2000,
        'eos_monthly_amount' => 500,
    ]);

    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        (int) $company->id,
        $employee,
        [
            'status' => 'active',
            'payroll_category' => PayrollCategory::Office->value,
            'salary_structure' => 'monthly',
            'basic_salary' => 15000,
            'housing_allowance' => 2000,
            'start_date' => '2026-01-01',
        ],
        $current,
        revisionEffectiveFrom: '2026-10-01',
    ))->toThrow(
        ValidationException::class,
        AssertContractSalaryStructureChangeAllowed::LATER_MONTH_MESSAGE,
    );

    expect($older->fresh()->status)->toBe('active')
        ->and($older->fresh()->end_date)->toBeNull()
        ->and($current->fresh()->payroll_category)->toBe(PayrollCategory::Crew);
});
