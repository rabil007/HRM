<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('employees index filters by department subtree', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'DST',
        'name' => 'Deptland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'DST',
        'name' => 'Dept Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Dept Co',
        'slug' => 'dept-co',
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

    $otherDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Shore',
        'parent_id' => null,
    ]);

    $parentEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DST-PARENT',
        'name' => 'Parent Employee',
        'department_id' => $parentDepartment->id,
    ]);

    $childEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DST-CHILD',
        'name' => 'Child Employee',
        'department_id' => $childDepartment->id,
    ]);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DST-OTHER',
        'name' => 'Other Employee',
        'department_id' => $otherDepartment->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?department_id='.$parentDepartment->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('department_tree')
            ->where('department_tree_selected_id', $parentDepartment->id)
            ->has('employees', 2)
            ->where('employees', fn ($employees) => collect($employees)->pluck('id')->contains($parentEmployee->id)
                && collect($employees)->pluck('id')->contains($childEmployee->id)
                && ! collect($employees)->pluck('id')->contains($otherEmployee->id))
        );
});

test('employees index department tree rolls up employee counts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'DTR',
        'name' => 'Treeland',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'DTR',
        'name' => 'Tree Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Tree Co',
        'slug' => 'tree-co',
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

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('department_tree')
            ->where('department_tree', function ($tree) use ($parentDepartment, $childDepartment) {
                $allNode = collect($tree)->firstWhere('id', null);
                $parentNode = collect($tree)->firstWhere('id', $parentDepartment->id);
                $childNode = collect($parentNode['children'] ?? [])->firstWhere('id', $childDepartment->id);

                return $allNode['count'] === 5
                    && $parentNode['count'] === 5
                    && $childNode['count'] === 3;
            })
        );
});
