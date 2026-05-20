<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Http\Request;

test('employee export query uses the same directory filters as the index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'EXP',
        'name' => 'Exportland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'EXP',
        'name' => 'Export Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Export Co',
        'slug' => 'export-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $parentDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'parent_id' => null,
    ]);

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'parent_id' => $parentDepartment->id,
    ]);

    $otherDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Administration',
        'parent_id' => null,
    ]);

    $parentEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-PARENT',
        'department_id' => $parentDepartment->id,
    ]);

    $childEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-CHILD',
        'department_id' => $childDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-OTHER',
        'department_id' => $otherDepartment->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $request = Request::create(
        '/organization/employees/export',
        'GET',
        ['department_id' => (string) $parentDepartment->id, 'format' => 'csv'],
    );
    $request->attributes->set('current_company_id', $company->id);

    $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);

    $exportIds = (new EmployeeDirectoryQuery($company->id, $directoryFilters))
        ->apply(Employee::query())
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $indexResponse = $this->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?department_id='.$parentDepartment->id)
        ->assertOk();

    $indexIds = collect($indexResponse->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($exportIds)->toBe([$parentEmployee->id, $childEmployee->id])
        ->and($indexIds)->toBe($exportIds);
});
