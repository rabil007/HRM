<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\DepartmentDescendantIds;
use App\Support\Employees\EmployeeDirectoryFilters;

test('department descendant ids includes self and nested children', function () {
    $departments = [
        ['id' => 1, 'parent_id' => null],
        ['id' => 2, 'parent_id' => 1],
        ['id' => 3, 'parent_id' => 2],
        ['id' => 4, 'parent_id' => null],
    ];

    expect(DepartmentDescendantIds::includingSelf(1, $departments))
        ->toEqual([1, 2, 3]);

    expect(DepartmentDescendantIds::includingSelf(4, $departments))
        ->toEqual([4]);
});

test('build department employee tree rolls up counts to ancestors', function () {
    $country = Country::query()->create([
        'code' => 'BDT',
        'name' => 'Buildland',
        'dial_code' => '+973',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BDT',
        'name' => 'Build Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Build Co',
        'slug' => 'build-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $parentDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Junior Officers',
        'parent_id' => $parentDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->count(2)->create([
        'department_id' => $parentDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->count(3)->create([
        'department_id' => $childDepartment->id,
    ]);

    $tree = BuildDepartmentEmployeeTree::for($company->id, new EmployeeDirectoryFilters);

    $allNode = collect($tree)->firstWhere('id', null);
    $parentNode = collect($tree)->firstWhere('id', $parentDepartment->id);
    $childNode = collect($parentNode['children'])->firstWhere('id', $childDepartment->id);

    expect($allNode['count'])->toBe(5);
    expect($parentNode['count'])->toBe(5);
    expect($childNode['count'])->toBe(3);
});
