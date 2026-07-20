<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('department show employee count excludes soft-deleted and other-company employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->firstOrCreate(
        ['code' => 'DEC'],
        ['name' => 'Dept Count Land', 'dial_code' => '+901', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'DEC'],
        ['name' => 'Dept Count Currency', 'symbol' => 'D$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'Dept Count Co',
        'slug' => 'dept-count-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Dept Co',
        'slug' => 'other-dept-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Finance',
        'code' => 'FIN',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $deleted = Employee::factory()->forCompany($company)->create([
        'department_id' => $department->id,
        'status' => 'active',
    ]);
    $deleted->delete();

    Employee::factory()->forCompany($otherCompany)->create([
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.departments.show', $department))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/department')
            ->where('department.users_count', 1)
        );
});
