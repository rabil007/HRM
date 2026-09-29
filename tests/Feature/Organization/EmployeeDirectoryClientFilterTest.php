<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('employees index filters by client and exposes client options including referenced inactive clients', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLF',
        'name' => 'Client Filter Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLF',
        'name' => 'Client Filter Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Filter Co',
        'slug' => 'client-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $adnoc = Client::query()->create([
        'name' => 'ADNOC',
        'is_active' => true,
    ]);

    $npcc = Client::query()->create([
        'name' => 'NPCC',
        'is_active' => true,
    ]);

    $referencedInactive = Client::query()->create([
        'name' => 'Legacy Inactive Client',
        'is_active' => false,
    ]);

    Client::query()->create([
        'name' => 'Unreferenced Inactive Client',
        'is_active' => false,
    ]);

    $matched = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CLF-MATCH',
        'name' => 'ADNOC Employee',
        'client_id' => $adnoc->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CLF-OTHER',
        'name' => 'NPCC Employee',
        'client_id' => $npcc->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CLF-LEGACY',
        'name' => 'Legacy Inactive Client Employee',
        'client_id' => $referencedInactive->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CLF-NONE',
        'name' => 'No Client Employee',
        'client_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?client_id='.$adnoc->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('filters.client_id', (string) $adnoc->id)
            ->has('employees', 1)
            ->where('employees.0.id', $matched->id)
            ->has('clients', 3)
            ->where('clients.0.name', 'ADNOC')
            ->where('clients.1.name', 'Legacy Inactive Client')
            ->where('clients.2.name', 'NPCC'));
});

test('client and project filters intersect naturally', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CPI',
        'name' => 'Intersection Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CPI',
        'name' => 'Intersection Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Intersection Co',
        'slug' => 'intersection-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $adnoc = Client::query()->create(['name' => 'ADNOC', 'is_active' => true]);
    $npcc = Client::query()->create(['name' => 'NPCC', 'is_active' => true]);

    $projectA = Project::query()->create(['title' => 'Project A', 'is_active' => true]);
    $projectA->clients()->sync([$adnoc->id]);
    $projectB = Project::query()->create(['title' => 'Project B', 'is_active' => true]);
    $projectB->clients()->sync([$adnoc->id]);
    $projectC = Project::query()->create(['title' => 'Project C', 'is_active' => true]);
    $projectC->clients()->sync([$npcc->id]);

    $matched = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'INT-1',
        'client_id' => $adnoc->id,
        'project_id' => $projectA->id,
        'status' => 'active',
    ]);

    // Same client, different project
    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'INT-2',
        'client_id' => $adnoc->id,
        'project_id' => $projectB->id,
        'status' => 'active',
    ]);

    // Different client and project
    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'INT-3',
        'client_id' => $npcc->id,
        'project_id' => $projectC->id,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?client_id={$adnoc->id}&project_id={$projectA->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('filters.client_id', (string) $adnoc->id)
            ->where('filters.project_id', (string) $projectA->id)
            ->has('employees', 1)
            ->where('employees.0.id', $matched->id));
});

test('client filter respects company and department visibility scope', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SC1',
        'name' => 'Scope Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SC1',
        'name' => 'Scope Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company1 = Company::query()->create([
        'name' => 'Scope Co 1',
        'slug' => 'scope-co-1',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $company2 = Company::query()->create([
        'name' => 'Scope Co 2',
        'slug' => 'scope-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $deptA = Department::query()->create([
        'company_id' => $company1->id,
        'name' => 'Allowed Dept',
        'include_in_attendance_leave' => true,
    ]);

    $deptB = Department::query()->create([
        'company_id' => $company1->id,
        'name' => 'Restricted Dept',
        'include_in_attendance_leave' => true,
    ]);

    $client = Client::query()->create(['name' => 'ADNOC', 'is_active' => true]);

    $visibleEmployee = Employee::factory()->forCompany($company1)->create([
        'employee_no' => 'VIS-1',
        'department_id' => $deptA->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $restrictedDeptEmployee = Employee::factory()->forCompany($company1)->create([
        'employee_no' => 'REST-1',
        'department_id' => $deptB->id,
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $otherCompanyEmployee = Employee::factory()->forCompany($company2)->create([
        'employee_no' => 'OTHER-CO',
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    // Give user permission restricted to deptA
    grantCompanyPermissions($user, $company1, ['employees.view']);
    restrictUserToDepartments($user, $company1, [$deptA->id]);

    $filters = EmployeeDirectoryFilters::fromArray(['client_id' => (string) $client->id]);

    $results = (new EmployeeDirectoryQuery($company1->id, $filters, $user))
        ->apply(Employee::query())
        ->pluck('id')
        ->all();

    expect($results)->toBe([$visibleEmployee->id])
        ->and($results)->not->toContain($restrictedDeptEmployee->id)
        ->and($results)->not->toContain($otherCompanyEmployee->id);
});

test('client_id survives query serialization, inertia filters, and list-query preservation', function () {
    $filters = EmployeeDirectoryFilters::fromArray([
        'client_id' => '42',
        'search' => 'Alice',
    ]);

    expect($filters->clientId)->toBe('42')
        ->and($filters->toQueryArray())->toHaveKey('client_id', '42')
        ->and($filters->toInertiaFilters())->toHaveKey('client_id', '42');

    $request = Request::create('/organization/employees?client_id=42&search=Alice', 'GET');
    $listQuery = EmployeeDirectoryFilters::listQueryFromRequest($request);

    expect($listQuery)->toHaveKey('client_id', '42')
        ->and($listQuery)->toHaveKey('search', 'Alice');

    // Also via Referer header
    $requestWithReferer = Request::create('/organization/employees/1/edit', 'GET');
    $requestWithReferer->headers->set('referer', 'http://localhost/organization/employees?client_id=42');

    $refererQuery = EmployeeDirectoryFilters::listQueryFromRequest($requestWithReferer);
    expect($refererQuery)->toHaveKey('client_id', '42');
});

test('employee export honors client filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'EXP2',
        'name' => 'Export Land 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'EXP2',
        'name' => 'Export Currency 2',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Export Client Co',
        'slug' => 'export-client-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $clientA = Client::query()->create(['name' => 'ADNOC', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'NPCC', 'is_active' => true]);

    $empA = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-A',
        'client_id' => $clientA->id,
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-B',
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

    expect($exportIds)->toBe([$empA->id]);
});
