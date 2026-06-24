<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCompanyForManagerResolver(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => $suffix,
        'name' => "Country {$suffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $suffix,
        'name' => "Currency {$suffix}",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => "Company {$suffix}",
        'slug' => strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('effective manager resolves from parent department when child has no manager', function () {
    $company = createCompanyForManagerResolver('DMR');

    $manager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGR100',
        'name' => 'Department Manager',
    ]);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $child = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => $parent->id,
        'name' => 'IT',
        'code' => 'IT',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->inDepartment($child)->create([
        'employee_no' => 'EMP100',
        'name' => 'Child Employee',
    ]);

    expect(ResolveDepartmentEffectiveManager::managerForEmployee($employee))
        ->not->toBeNull()
        ->id->toBe($manager->id)
        ->and(ResolveDepartmentEffectiveManager::departmentIdsForManager($company->id, $manager->id))
        ->toContain($parent->id, $child->id);
});

test('employee without department has no resolved manager', function () {
    $employee = Employee::factory()->create([
        'department_id' => null,
    ]);

    expect(ResolveDepartmentEffectiveManager::managerForEmployee($employee))->toBeNull();
});

test('employee in department without manager chain has no resolved manager', function () {
    $company = createCompanyForManagerResolver('NMR');

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Unmanaged',
        'code' => 'UNM',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->inDepartment($department)->create();

    expect(ResolveDepartmentEffectiveManager::managerForEmployee($employee))->toBeNull();
});
