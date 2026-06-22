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

test('sync all command backfills salary components for existing contracts', function () {
    $country = Country::query()->create([
        'code' => 'SAC',
        'name' => 'Sync All Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SAC',
        'name' => 'Sync All Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Sync All Co',
        'slug' => 'sync-all-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SAC-1',
    ]);

    $officeContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'ended',
        'basic_salary' => 7000,
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SAC-2',
    ]);

    $crewContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 50,
        'site_allowance' => 300,
    ]);

    expect(ContractSalaryComponent::query()->count())->toBe(0);

    $this->artisan('payroll:sync-contract-salary-components', [
        '--company' => $company->id,
    ])->assertSuccessful();

    expect(ContractSalaryComponent::query()->where('contract_id', $officeContract->id)->count())->toBe(1)
        ->and(
            ContractSalaryComponent::query()
                ->where('contract_id', $officeContract->id)
                ->where('component_code', SalaryComponentCode::Basic->value)
                ->where('status', SalaryComponentStatus::Active->value)
                ->exists(),
        )->toBeTrue()
        ->and(ContractSalaryComponent::query()->where('contract_id', $crewContract->id)->count())->toBe(2)
        ->and(
            ContractSalaryComponent::query()
                ->where('contract_id', $crewContract->id)
                ->where('component_code', SalaryComponentCode::Basic->value)
                ->first()
                ?->rate_type->value,
        )->toBe('daily');
});

test('sync all command dry run does not create components', function () {
    $country = Country::query()->create([
        'code' => 'SAD',
        'name' => 'Sync All Dry Country',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SAD',
        'name' => 'Sync All Dry Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Sync All Dry Co',
        'slug' => 'sync-all-dry-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SAD-1',
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    $this->artisan('payroll:sync-contract-salary-components', [
        '--company' => $company->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(ContractSalaryComponent::query()->count())->toBe(0);
});
