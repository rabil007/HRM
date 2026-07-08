<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\ContractDepartmentTree;
use App\Support\Contracts\ContractDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryFilters;

test('contract department tree limits roots and counts to selected payroll category workforce', function () {
    $country = Country::query()->create([
        'code' => 'CDT',
        'name' => 'Contract Dept Tree Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CDT',
        'name' => 'Contract Dept Tree Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contract Dept Tree Co',
        'slug' => 'contract-dept-tree-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeRoot = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'parent_id' => null,
    ]);

    $offshoreRoot = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create([
        'department_id' => $officeRoot->id,
        'status' => 'active',
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'department_id' => $offshoreRoot->id,
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'daily',
        'status' => 'active',
    ]);

    $filters = new EmployeeDirectoryFilters;
    $officeTree = ContractDepartmentTree::for(
        $company->id,
        $filters,
        ContractDepartmentTree::CONTEXT_INDEX,
        new ContractDirectoryFilters(payrollCategory: PayrollCategory::Office->value),
    );
    $crewTree = ContractDepartmentTree::for(
        $company->id,
        $filters,
        ContractDepartmentTree::CONTEXT_INDEX,
        new ContractDirectoryFilters(
            payrollCategory: PayrollCategory::Crew->value,
            salaryStructure: 'daily',
        ),
    );

    $officeAllNode = collect($officeTree)->firstWhere('id', null);
    $crewAllNode = collect($crewTree)->firstWhere('id', null);
    $officeRootNode = collect($officeTree)->firstWhere('id', $officeRoot->id);
    $offshoreRootNode = collect($crewTree)->firstWhere('id', $offshoreRoot->id);

    expect($officeAllNode['count'])->toBe(1)
        ->and($crewAllNode['count'])->toBe(1)
        ->and($officeRootNode['count'])->toBe(1)
        ->and($offshoreRootNode['count'])->toBe(1)
        ->and(collect($officeTree)->contains('id', $offshoreRoot->id))->toBeFalse()
        ->and(collect($crewTree)->contains('id', $officeRoot->id))->toBeFalse();
});

test('contract no-contract department tree scopes employees by payroll category workforce', function () {
    $country = Country::query()->create([
        'code' => 'CNT',
        'name' => 'No Contract Dept Tree Land',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CNT',
        'name' => 'No Contract Dept Tree Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'No Contract Dept Tree Co',
        'slug' => 'no-contract-dept-tree-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeRoot = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'parent_id' => null,
    ]);

    $offshoreRoot = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    Employee::query()->create([
        'company_id' => $company->id,
        'department_id' => $officeRoot->id,
        'employee_no' => 'OFF-NC-1',
        'name' => 'Office No Contract 1',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'company_id' => $company->id,
        'department_id' => $officeRoot->id,
        'employee_no' => 'OFF-NC-2',
        'name' => 'Office No Contract 2',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'company_id' => $company->id,
        'department_id' => $offshoreRoot->id,
        'employee_no' => 'CRW-NC-1',
        'name' => 'Crew No Contract 1',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'company_id' => $company->id,
        'department_id' => $offshoreRoot->id,
        'employee_no' => 'CRW-NC-2',
        'name' => 'Crew No Contract 2',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'company_id' => $company->id,
        'department_id' => $offshoreRoot->id,
        'employee_no' => 'CRW-NC-3',
        'name' => 'Crew No Contract 3',
        'status' => 'active',
    ]);

    $filters = new EmployeeDirectoryFilters;
    $officeTree = ContractDepartmentTree::for(
        $company->id,
        $filters,
        ContractDepartmentTree::CONTEXT_NO_CONTRACT,
        new ContractDirectoryFilters(payrollCategory: PayrollCategory::Office->value),
    );
    $crewTree = ContractDepartmentTree::for(
        $company->id,
        $filters,
        ContractDepartmentTree::CONTEXT_NO_CONTRACT,
        new ContractDirectoryFilters(payrollCategory: PayrollCategory::Crew->value),
    );

    expect(collect($officeTree)->firstWhere('id', null)['count'])->toBe(2)
        ->and(collect($crewTree)->firstWhere('id', null)['count'])->toBe(3);
});
