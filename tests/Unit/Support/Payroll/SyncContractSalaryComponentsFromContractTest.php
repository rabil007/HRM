<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\Company;
use App\Models\ContractSalaryComponent;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;

test('sync creates office salary components from legacy contract columns', function () {
    $company = makeSyncContractSalaryComponentsCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SSC-OFFICE',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
        'other_allowances' => 250,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    $components = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->orderBy('component_code')
        ->get();

    expect($components)->toHaveCount(4)
        ->and($components->firstWhere('component_code', SalaryComponentCode::Basic)?->amount)->toBe('5000.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Housing)?->amount)->toBe('2000.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Transport)?->amount)->toBe('1000.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Other)?->amount)->toBe('250.00');
});

test('sync creates crew salary components from legacy contract columns', function () {
    $company = makeSyncContractSalaryComponentsCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SSC-CREW',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 150,
        'supplementary_allowance' => 75,
        'site_allowance' => 50,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    $components = ContractSalaryComponent::query()
        ->where('contract_id', $contract->id)
        ->get();

    expect($components)->toHaveCount(3)
        ->and($components->firstWhere('component_code', SalaryComponentCode::Basic)?->amount)->toBe('150.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::Basic)?->rate_type->value)->toBe('daily')
        ->and($components->firstWhere('component_code', SalaryComponentCode::SupplementaryAllowance)?->amount)->toBe('75.00')
        ->and($components->firstWhere('component_code', SalaryComponentCode::SiteAllowance)?->amount)->toBe('50.00');
});

test('sync deactivates obsolete standby rate when crew contract uses basic', function () {
    $company = makeSyncContractSalaryComponentsCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SSC-OBS',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 50,
    ]);

    ContractSalaryComponent::query()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'component_code' => SalaryComponentCode::StandbyRate->value,
        'component_name' => 'Standby rate',
        'rate_type' => 'daily',
        'amount' => 50,
        'status' => SalaryComponentStatus::Active->value,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    expect(
        ContractSalaryComponent::query()
            ->where('contract_id', $contract->id)
            ->where('component_code', SalaryComponentCode::StandbyRate->value)
            ->first()
            ?->status,
    )->toBe(SalaryComponentStatus::Inactive)
        ->and(
            ContractSalaryComponent::query()
                ->where('contract_id', $contract->id)
                ->where('component_code', SalaryComponentCode::Basic->value)
                ->first()
                ?->status,
        )->toBe(SalaryComponentStatus::Active);
});

test('sync deactivates obsolete ot rate component for crew contracts', function () {
    $company = makeSyncContractSalaryComponentsCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SSC-OT',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 50,
    ]);

    ContractSalaryComponent::query()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'component_code' => SalaryComponentCode::OtRate->value,
        'component_name' => 'OT rate',
        'rate_type' => 'hourly',
        'amount' => 25,
        'status' => SalaryComponentStatus::Active->value,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    expect(
        ContractSalaryComponent::query()
            ->where('contract_id', $contract->id)
            ->where('component_code', SalaryComponentCode::OtRate->value)
            ->first()
            ?->status,
    )->toBe(SalaryComponentStatus::Inactive);
});

test('sync deactivates components when legacy amount is cleared', function () {
    $company = makeSyncContractSalaryComponentsCompany();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SSC-CLEAR',
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    $contract->update(['basic_salary' => null]);
    (new SyncContractSalaryComponentsFromContract)->handle($contract->fresh());

    expect(
        ContractSalaryComponent::query()
            ->where('contract_id', $contract->id)
            ->where('component_code', SalaryComponentCode::Basic->value)
            ->first()
            ?->status,
    )->toBe(SalaryComponentStatus::Inactive);
});

function makeSyncContractSalaryComponentsCompany(): Company
{
    $country = Country::query()->create([
        'code' => 'SSC',
        'name' => 'Salary Component Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SSC',
        'name' => 'Salary Component Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Salary Component Co',
        'slug' => 'salary-component-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}
