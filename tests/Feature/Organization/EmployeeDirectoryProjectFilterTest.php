<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('employees index filters by project and exposes project options', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'PRF',
        'name' => 'Project Filter Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PRF',
        'name' => 'Project Filter Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Project Filter Co',
        'slug' => 'project-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $adnoc = Project::query()->create([
        'title' => 'ADNOC',
        'is_active' => true,
    ]);

    $aramco = Project::query()->create([
        'title' => 'Aramco',
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Inactive Project',
        'is_active' => false,
    ]);

    $matched = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'PRF-MATCH',
        'name' => 'Matched Project Employee',
        'project_id' => $adnoc->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'PRF-OTHER',
        'name' => 'Other Project Employee',
        'project_id' => $aramco->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'PRF-NONE',
        'name' => 'No Project Employee',
        'project_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?project_id='.$adnoc->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('filters.project_id', (string) $adnoc->id)
            ->has('employees', 1)
            ->where('employees.0.id', $matched->id)
            ->has('projects', 2)
            ->where('projects.0.title', 'ADNOC')
            ->where('projects.1.title', 'Aramco'));
});
