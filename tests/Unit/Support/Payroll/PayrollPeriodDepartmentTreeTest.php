<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Payroll\PayrollPeriodBoardFilters;
use App\Support\Payroll\PayrollPeriodDepartmentTree;

test('payroll period department tree counts only employees matching board filters', function () {
    $country = Country::query()->create([
        'code' => 'PDT',
        'name' => 'Payroll Dept Tree Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PDT',
        'name' => 'Payroll Dept Tree Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Payroll Dept Tree Co',
        'slug' => 'payroll-dept-tree-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $operationsDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'parent_id' => null,
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $dailyEmployee = Employee::factory()->forCompany($company)->create([
        'department_id' => $operationsDepartment->id,
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $dailyEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'daily',
        'status' => 'active',
    ]);

    $monthlyEmployee = Employee::factory()->forCompany($company)->create([
        'department_id' => $marineDepartment->id,
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $monthlyEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'monthly',
        'status' => 'active',
    ]);

    $filters = new EmployeeDirectoryFilters;
    $allEmployeesTree = BuildDepartmentEmployeeTree::for($company->id, $filters);
    $dailyTree = PayrollPeriodDepartmentTree::for(
        $company->id,
        $period,
        $filters,
        null,
        new PayrollPeriodBoardFilters(crewSalaryStructure: 'daily'),
    );
    $monthlyTree = PayrollPeriodDepartmentTree::for(
        $company->id,
        $period,
        $filters,
        null,
        new PayrollPeriodBoardFilters(crewSalaryStructure: 'monthly'),
    );

    $allNode = collect($allEmployeesTree)->firstWhere('id', null);
    $dailyAllNode = collect($dailyTree)->firstWhere('id', null);
    $monthlyAllNode = collect($monthlyTree)->firstWhere('id', null);
    $operationsNode = collect($dailyTree)->firstWhere('id', $operationsDepartment->id);
    $marineNode = collect($monthlyTree)->firstWhere('id', $marineDepartment->id);

    expect($allNode['count'])->toBe(2)
        ->and($dailyAllNode['count'])->toBe(1)
        ->and($monthlyAllNode['count'])->toBe(1)
        ->and($operationsNode['count'])->toBe(1)
        ->and($marineNode['count'])->toBe(1);
});
