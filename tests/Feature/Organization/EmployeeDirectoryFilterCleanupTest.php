<?php

use App\Enums\SavedViewPage;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\SavedViews\SavedViewCatalog;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function makeFilterCleanupFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CLU',
        'name' => 'Cleanup Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLU',
        'name' => 'Cleanup Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Cleanup Co',
        'slug' => 'cleanup-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branchA = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Branch Alpha',
        'code' => 'BA',
        'status' => 'active',
    ]);

    $branchB = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Branch Beta',
        'code' => 'BB',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $positionA = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Captain',
        'status' => 'active',
    ]);

    $positionB = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Chief Engineer',
        'status' => 'active',
    ]);

    $rankA = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Master',
        'status' => 'active', 'is_crew_position' => true,
    ]);

    $rankB = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Mate',
        'status' => 'active', 'is_crew_position' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC',
        'is_active' => true,
    ]);

    $project = Project::query()->create([
        'title' => 'Offshore Phase 1',
        'is_active' => true,
    ]);
    $project->clients()->sync([$client->id]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    return compact(
        'user',
        'company',
        'branchA',
        'branchB',
        'department',
        'positionA',
        'positionB',
        'rankA',
        'rankB',
        'client',
        'project',
    );
}

test('employee directory does not apply branch_id and does not expose branches prop', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $emp1 = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CL-001',
        'name' => 'Emp One',
        'branch_id' => $fixtures['branchA']->id,
        'status' => 'active',
    ]);

    $emp2 = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CL-002',
        'name' => 'Emp Two',
        'branch_id' => $fixtures['branchB']->id,
        'status' => 'active',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?branch_id={$fixtures['branchA']->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 2)
            ->missing('branches')
            ->missing('filters.branch_id'));
});

test('employee directory does not apply crew_status parameter', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CS-001',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CS-002',
        'status' => 'active',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?crew_status=on_vessel')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 2)
            ->missing('filters.crew_status'));
});

test('obsolete query parameters are not propagated to pagination links', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'PG-001',
        'status' => 'active',
    ]);

    $response = $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?branch_id={$fixtures['branchA']->id}&crew_status=on_vessel&status=active");

    $response->assertOk();
    $response->assertInertia(function (Assert $page) {
        $page->component('organization/employees')
            ->where('filters.status', 'active')
            ->missing('filters.branch_id')
            ->missing('filters.crew_status');

        $pagination = $page->toArray()['props']['pagination'] ?? [];
        $firstPageUrl = $pagination['first_page_url'] ?? '';

        expect($firstPageUrl)
            ->not->toContain('branch_id')
            ->not->toContain('crew_status');
    });
});

test('employee directory filters contract ignores branch_id and crew_status', function () {
    $filters = EmployeeDirectoryFilters::fromArray([
        'branch_id' => '10',
        'crew_status' => 'on_vessel',
        'status' => 'active',
        'position_id' => '5',
    ]);

    $queryArray = $filters->toQueryArray();
    expect($queryArray)->toEqual([
        'position_id' => '5',
        'status' => 'active',
    ])
        ->and(array_key_exists('branch_id', $queryArray))->toBeFalse()
        ->and(array_key_exists('crew_status', $queryArray))->toBeFalse();

    $inertiaFilters = $filters->toInertiaFilters();
    expect(array_key_exists('branch_id', $inertiaFilters))->toBeFalse()
        ->and(array_key_exists('crew_status', $inertiaFilters))->toBeFalse();
});

test('legacy saved views with branch_id and crew_status load safely with obsolete keys dropped', function () {
    $legacyRaw = [
        'status' => 'active',
        'branch_id' => '2',
        'crew_status' => 'on_vessel',
        'missing_fields' => 'emirates_id',
    ];

    $applied = SavedViewCatalog::forApply(SavedViewPage::Employees, $legacyRaw);
    expect($applied)->toBe([
        'status' => 'active',
        'missing_fields' => 'emirates_id',
    ]);

    $normalized = SavedViewCatalog::normalizeForSave(SavedViewPage::Employees, $legacyRaw, 1);
    expect($normalized)->toBe([
        'status' => 'active',
        'missing_fields' => 'emirates_id',
    ]);
});

test('existing HR status, position, and rank filtering continues to work', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $activeCaptain = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'VAL-001',
        'name' => 'Active Captain',
        'status' => 'active',
        'position_id' => $fixtures['positionA']->id,
    ]);

    $inactiveCaptain = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'VAL-002',
        'name' => 'Inactive Captain',
        'status' => 'inactive',
        'position_id' => $fixtures['positionA']->id,
    ]);

    $activeEngineer = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'VAL-003',
        'name' => 'Active Engineer',
        'status' => 'active',
        'position_id' => $fixtures['positionB']->id,
    ]);

    // Test HR Status filter
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?status=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('filters.status', 'active'));

    // Test Position filter
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?position_id={$fixtures['positionA']->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2));

    // Test Position filter (legacy rank_id query param is ignored after Rank retirement)
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?position_id={$fixtures['positionB']->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $activeEngineer->id));
});

test('existing manager and role filtering continues to work', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $manager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGR-001',
        'name' => 'Team Manager',
        'status' => 'active',
    ]);

    $managedDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Managed Dept',
        'code' => 'MNG',
        'manager_id' => $manager->id,
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $report = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'REP-001',
        'name' => 'Direct Report',
        'department_id' => $managedDept->id,
        'status' => 'active',
    ]);

    $roleUser = User::factory()->create();
    $spatieRole = Role::findOrCreate('Specialist', 'web');
    $spatieRole->company_id = $company->id;
    $spatieRole->save();
    $roleUser->assignRole($spatieRole);

    $roledEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'ROL-001',
        'name' => 'Roled Employee',
        'user_id' => $roleUser->id,
        'status' => 'active',
    ]);

    // Test Manager filter
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?manager_id={$manager->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $report->id));

    // Test Role filter
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?role_id={$spatieRole->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $roledEmployee->id));
});

test('client and project dependent filtering continues to work', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $otherClient = Client::query()->create(['name' => 'NPCC', 'is_active' => true]);
    $otherProject = Project::query()->create(['title' => 'NPCC Yard', 'is_active' => true]);
    $otherProject->clients()->sync([$otherClient->id]);

    $empAdnoc = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DEP-001',
        'client_id' => $fixtures['client']->id,
        'project_id' => $fixtures['project']->id,
        'status' => 'active',
    ]);

    $empNpcc = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DEP-002',
        'client_id' => $otherClient->id,
        'project_id' => $otherProject->id,
        'status' => 'active',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees?client_id={$fixtures['client']->id}&project_id={$fixtures['project']->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $empAdnoc->id));
});

test('employee visibility scope still restricts unauthorized departments', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $restrictedDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Confidential Executive',
        'include_in_attendance_leave' => true,
    ]);

    $visibleEmp = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'VIS-101',
        'department_id' => $fixtures['department']->id,
        'status' => 'active',
    ]);

    $hiddenEmp = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HID-101',
        'department_id' => $restrictedDept->id,
        'status' => 'active',
    ]);

    // User only has department scope on $fixtures['department']
    $restrictedUser = User::factory()->create();
    $fixtures['user']->current_company_id = $company->id;

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees?status=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)); // User with general employees.view sees all in company
});

test('employee export ignores branch_id and crew_status but respects valid filters', function () {
    $fixtures = makeFilterCleanupFixtures();
    $company = $fixtures['company'];

    $match = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-001',
        'client_id' => $fixtures['client']->id,
        'branch_id' => $fixtures['branchA']->id,
        'status' => 'active',
    ]);

    $other = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EXP-002',
        'client_id' => null,
        'branch_id' => $fixtures['branchB']->id,
        'status' => 'active',
    ]);

    $request = Request::create(
        '/organization/employees/export',
        'GET',
        [
            'client_id' => (string) $fixtures['client']->id,
            'branch_id' => (string) $fixtures['branchB']->id, // Should be ignored
            'crew_status' => 'on_vessel', // Should be ignored
            'format' => 'csv',
        ],
    );
    $request->attributes->set('current_company_id', $company->id);

    $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);

    $exportIds = (new EmployeeDirectoryQuery($company->id, $directoryFilters))
        ->apply(Employee::query())
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($exportIds)->toBe([$match->id]);
});
