<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     marineDept: Department,
 *     officeDept: Department,
 *     marineEmployee: Employee,
 *     officeEmployee: Employee
 * }
 */
function makeEmployeeVisibilityFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->firstOrCreate(
        ['code' => 'EVS'],
        ['name' => 'Visibility Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'EVS'],
        ['name' => 'Visibility Currency', 'symbol' => 'V$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'Visibility Co',
        'slug' => 'visibility-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $marineDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
    ]);

    $officeDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $marineEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $marineDept->id,
        'status' => 'active',
        'name' => 'Marine Crew',
    ]);

    $officeEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $officeDept->id,
        'status' => 'active',
        'name' => 'Office Staff',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    return compact(
        'user',
        'company',
        'marineDept',
        'officeDept',
        'marineEmployee',
        'officeEmployee',
    );
}

function restrictUserToDepartments(User $user, Company $company, array $departmentIds): Role
{
    restrictTestRoleEmployeeVisibility($user, $company, $departmentIds);

    return $user->roles()
        ->where('spatie_roles.company_id', $company->id)
        ->where('spatie_roles.name', 'test-role')
        ->firstOrFail();
}
