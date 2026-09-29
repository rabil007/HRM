<?php

use App\Models\Client;
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
        'include_in_attendance_leave' => true,
    ]);

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'parent_id' => $parentDepartment->id,
        'include_in_attendance_leave' => true,
    ]);

    $otherDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Administration',
        'parent_id' => null,
        'include_in_attendance_leave' => true,
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

test('employee export query and index query match with multiple department filters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Exportland', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Export Currency', 'symbol' => 'E$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'Multi Export Co',
        'slug' => 'multi-export-co-'.uniqid(),
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
        'include_in_attendance_leave' => true,
    ]);

    $deckDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'parent_id' => $operationsDepartment->id,
        'include_in_attendance_leave' => true,
    ]);

    $adminDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Administration',
        'parent_id' => null,
        'include_in_attendance_leave' => true,
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'HR',
        'parent_id' => null,
        'include_in_attendance_leave' => true,
    ]);

    $operationsEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-MULTI-OPS',
        'department_id' => $operationsDepartment->id,
    ]);

    $deckEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-MULTI-DECK',
        'department_id' => $deckDepartment->id,
    ]);

    $adminEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-MULTI-ADMIN',
        'department_id' => $adminDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-MULTI-HR',
        'department_id' => $hrDepartment->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $departmentIds = $operationsDepartment->id.','.$adminDepartment->id;
    $request = Request::create(
        '/organization/employees/export',
        'GET',
        ['department_ids' => $departmentIds, 'format' => 'csv'],
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
        ->get('/organization/employees?department_ids='.$departmentIds)
        ->assertOk();

    $indexIds = collect($indexResponse->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($exportIds)->toBe([
        $operationsEmployee->id,
        $deckEmployee->id,
        $adminEmployee->id,
    ])->and($indexIds)->toBe($exportIds);
});

test('employee export query and index query match when filtered by client_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Exportland', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'EXP'],
        ['name' => 'Export Currency', 'symbol' => 'E$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'Client Export Co',
        'slug' => 'client-export-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $clientA = Client::query()->create(['name' => 'Export Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Export Client B', 'is_active' => true]);

    $empA = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-CL-A',
        'client_id' => $clientA->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-CL-B',
        'client_id' => $clientB->id,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $request = Request::create(
        '/organization/employees/export',
        'GET',
        ['client_id' => (string) $clientA->id, 'format' => 'csv'],
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
        ->get('/organization/employees?client_id='.$clientA->id)
        ->assertOk();

    $indexIds = collect($indexResponse->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($exportIds)->toBe([$empA->id])
        ->and($indexIds)->toBe($exportIds);
});
