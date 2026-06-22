<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;

test('backfill command assigns crew for marine subtree and office for others', function () {
    $country = Country::query()->create([
        'code' => 'BPC',
        'name' => 'Backfill Payroll Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BPC',
        'name' => 'Backfill Payroll Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Backfill Payroll Co',
        'slug' => 'backfill-payroll-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $marineChildDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Junior Officers',
        'parent_id' => $marineDepartment->id,
    ]);

    $shoreDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Shore',
        'parent_id' => null,
    ]);

    $offshoreRootDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    $marineEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPC-MARINE',
        'department_id' => $marineDepartment->id,
    ]);

    $marineChildEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPC-CHILD',
        'department_id' => $marineChildDepartment->id,
    ]);

    $shoreEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPC-SHORE',
        'department_id' => $shoreDepartment->id,
    ]);

    $offshoreEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPC-OFFSHORE',
        'department_id' => $offshoreRootDepartment->id,
    ]);

    $noDepartmentEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPC-NONE',
        'department_id' => null,
    ]);

    $marineContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
    ]);

    $marineChildContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineChildEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
    ]);

    $shoreContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $shoreEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
    ]);

    $offshoreContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $offshoreEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
    ]);

    $noDepartmentContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $noDepartmentEmployee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew->value,
    ]);

    $this->artisan('payroll:backfill-contract-categories', [
        '--company' => $company->id,
    ])->assertSuccessful();

    expect($marineContract->fresh()->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($marineChildContract->fresh()->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($offshoreContract->fresh()->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($shoreContract->fresh()->payroll_category)->toBe(PayrollCategory::Office)
        ->and($noDepartmentContract->fresh()->payroll_category)->toBe(PayrollCategory::Office);
});

test('backfill command dry run does not update contracts', function () {
    $country = Country::query()->create([
        'code' => 'BPD',
        'name' => 'Backfill Dry Country',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BPD',
        'name' => 'Backfill Dry Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Backfill Dry Co',
        'slug' => 'backfill-dry-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BPD-1',
        'department_id' => $marineDepartment->id,
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office->value,
    ]);

    $this->artisan('payroll:backfill-contract-categories', [
        '--company' => $company->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($contract->fresh()->payroll_category)->toBe(PayrollCategory::Office);
});
