<?php

use App\Models\CrewAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Search\GlobalSearchQuery;
use Illuminate\Support\Facades\DB;

function searchAs(User $user, int $companyId, string $query, array $extra = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->getJson(route('search', array_merge(['q' => $query], $extra)));
}

function groupKeys(array $payload): array
{
    return collect($payload['groups'] ?? [])->pluck('key')->all();
}

function groupResults(array $payload, string $key): array
{
    $group = collect($payload['groups'] ?? [])->firstWhere('key', $key);

    return $group['results'] ?? [];
}

test('guests cannot use global search', function () {
    $this->getJson(route('search', ['q' => 'ab']))->assertUnauthorized();
});

test('short and empty queries return no record groups', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, '')->assertOk()->assertJsonPath('groups', []);
    searchAs($user, $company->id, 'a')->assertOk()->assertJsonPath('groups', []);
});

test('overly long queries are rejected', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, str_repeat('a', GlobalSearchQuery::MAX_QUERY_LENGTH + 1))
        ->assertUnprocessable();
});

test('company_id cannot be supplied by the client', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, 'ab', ['company_id' => $company->id])
        ->assertUnprocessable();
});

test('active-company employees are returned and foreign employees are not', function () {
    $user = User::factory()->create();
    ['company' => $companyA, 'employee' => $employeeA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();

    $employeeA->update(['name' => 'Searchable Alpha', 'employee_no' => 'EMP-ALPHA']);
    $employeeB->update(['name' => 'Searchable Bravo', 'employee_no' => 'EMP-BRAVO']);

    grantCompanyPermissions($user, $companyA, ['employees.view']);
    grantCompanyPermissions($user, $companyB, ['employees.view']);

    $payload = searchAs($user, $companyA->id, 'Searchable')->assertOk()->json();

    expect(groupKeys($payload))->toBe(['employees'])
        ->and(collect(groupResults($payload, 'employees'))->pluck('title')->all())
        ->toBe(['Searchable Alpha'])
        ->and(collect(groupResults($payload, 'employees'))->pluck('href')->all())
        ->toContain(route('organization.employees.show', $employeeA))
        ->and(json_encode($payload))->not->toContain('Searchable Bravo')
        ->and(collect(groupResults($payload, 'employees'))->pluck('id')->all())
        ->not->toContain('employee:'.$employeeB->id);
});

test('users without employees.view receive no employee group', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['name' => 'Hidden Worker']);
    grantCompanyPermissions($user, $company, ['departments.view']);

    $payload = searchAs($user, $company->id, 'Hidden')->assertOk()->json();

    expect(groupKeys($payload))->not->toContain('employees')
        ->and(json_encode($payload))->not->toContain('Hidden Worker');
});

test('employee prefix matches rank ahead of contains matches', function () {
    $user = User::factory()->create();
    ['company' => $company, 'branch' => $branch] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'ZZ-CONTAIN',
        'name' => 'Contains Emp12 Person',
        'status' => 'active',
    ]);
    $prefix = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'EMP12-001',
        'name' => 'Prefix Person',
        'status' => 'active',
    ]);

    $titles = collect(groupResults(
        searchAs($user, $company->id, 'EMP12')->assertOk()->json(),
        'employees',
    ))->pluck('title')->all();

    expect($titles[0] ?? null)->toBe($prefix->name);
});

test('authorized documents are returned and foreign documents are excluded', function () {
    $user = User::factory()->create();
    $home = makeDocumentFixtures();
    $foreign = makeDocumentFixtures();

    $own = createEmployeePdfDocument(
        $home['company']->id,
        $home['employee']->id,
        $home['passportType']->id,
        'docs/own-search.pdf',
        'own.pdf',
    );
    $own->update(['document_number' => 'PP-OWN-123', 'expiry_date' => '2027-10-12']);

    $other = createEmployeePdfDocument(
        $foreign['company']->id,
        $foreign['employee']->id,
        $foreign['passportType']->id,
        'docs/foreign-search.pdf',
        'foreign.pdf',
    );
    $other->update(['document_number' => 'PP-OWN-999']);

    grantCompanyPermissions($user, $home['company'], ['documents.view']);

    $payload = searchAs($user, $home['company']->id, 'PP-OWN')->assertOk()->json();

    expect(groupKeys($payload))->toBe(['documents'])
        ->and(collect(groupResults($payload, 'documents'))->pluck('href')->all())
        ->toContain(route('organization.documents.employee.files.show', [
            'employee' => $home['employee']->id,
            'document' => $own->id,
        ]))
        ->and(json_encode($payload))->not->toContain('PP-OWN-999')
        ->and(collect(groupResults($payload, 'documents'))->pluck('id')->all())
        ->not->toContain('document:'.$other->id);
});

test('users without documents.view receive no document metadata', function () {
    $user = User::factory()->create();
    $home = makeDocumentFixtures();
    $document = createEmployeePdfDocument(
        $home['company']->id,
        $home['employee']->id,
        $home['passportType']->id,
        'docs/secret-search.pdf',
        'secret.pdf',
    );
    $document->update(['document_number' => 'SECRET-DOC-1']);
    grantCompanyPermissions($user, $home['company'], ['employees.view']);

    $payload = searchAs($user, $home['company']->id, 'SECRET-DOC')->assertOk()->json();

    expect(groupKeys($payload))->not->toContain('documents')
        ->and(json_encode($payload))->not->toContain('SECRET-DOC-1');
});

test('crew assignments are company and permission scoped', function () {
    $home = makeCrewOperationsFixtures();
    $foreign = makeCrewOperationsFixtures();

    $own = CrewAssignment::factory()->forEmployee($home['employee'])->onVessel()->create([
        'assignment_no' => 'CA-SEARCH-OWN',
    ]);
    CrewAssignment::factory()->forEmployee($foreign['employee'])->create([
        'assignment_no' => 'CA-SEARCH-FOREIGN',
    ]);

    grantCompanyPermissions($home['user'], $home['company'], ['crew_operations.assignments.view']);

    $payload = searchAs($home['user'], $home['company']->id, 'CA-SEARCH')->assertOk()->json();

    expect(groupKeys($payload))->toBe(['crew'])
        ->and(collect(groupResults($payload, 'crew'))->pluck('title')->all())->toBe(['CA-SEARCH-OWN'])
        ->and(collect(groupResults($payload, 'crew'))->pluck('href')->all())
        ->toContain(route('organization.crew-assignments.show', $own))
        ->and(json_encode($payload))->not->toContain('CA-SEARCH-FOREIGN');
});

test('vessels, departments, and positions respect tenant scope and permissions', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB] = makeDocumentFixtures();

    $department = Department::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Marine Search Dept',
        'code' => 'MSD',
        'status' => 'active',
    ]);
    Department::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Marine Search Dept',
        'code' => 'MSX',
        'status' => 'active',
    ]);
    $position = Position::query()->create([
        'company_id' => $companyA->id,
        'department_id' => $department->id,
        'title' => 'Search Engineer',
        'grade' => 'G1',
        'min_salary' => 9000,
        'max_salary' => 12000,
        'status' => 'active',
    ]);
    $vessel = Vessel::factory()->forCompany($companyA)->create([
        'name' => 'Horizon Search Vessel',
        'imo_no' => 'IMO1234567',
    ]);
    Vessel::factory()->forCompany($companyB)->create([
        'name' => 'Horizon Search Vessel',
        'imo_no' => 'IMO9999999',
    ]);

    grantCompanyPermissions($user, $companyA, [
        'departments.view',
        'positions.view',
        'crew_operations.vessels.view',
    ]);

    $payload = searchAs($user, $companyA->id, 'Horizon')->assertOk()->json();
    $orgPayload = searchAs($user, $companyA->id, 'Marine Search')->assertOk()->json();
    $positionPayload = searchAs($user, $companyA->id, 'Search Engineer')->assertOk()->json();

    expect(groupKeys($payload))->toBe(['vessels'])
        ->and(collect(groupResults($payload, 'vessels'))->pluck('href')->all())
        ->toContain(route('organization.vessels.show', $vessel))
        ->and(json_encode($payload))->not->toContain('IMO9999999')
        ->and(groupKeys($orgPayload))->toContain('departments')
        ->and(collect(groupResults($orgPayload, 'departments'))->pluck('href')->all())
        ->toContain(route('organization.departments.show', $department))
        ->and(json_encode($orgPayload))->not->toContain('MSX')
        ->and(collect(groupResults($positionPayload, 'positions'))->pluck('href')->all())
        ->toContain(route('organization.positions.show', $position))
        ->and(json_encode($positionPayload))->not->toContain('9000');
});

test('payroll periods are returned without totals and foreign periods are excluded', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB] = makeDocumentFixtures();

    $period = PayrollPeriod::factory()->create([
        'company_id' => $companyA->id,
        'name' => 'August 2026 Search',
    ]);
    PayrollPeriod::factory()->create([
        'company_id' => $companyB->id,
        'name' => 'August 2026 Search',
    ]);

    grantCompanyPermissions($user, $companyA, ['payroll.periods.view']);

    $payload = searchAs($user, $companyA->id, 'August 2026')->assertOk()->json();
    $result = groupResults($payload, 'payroll')[0] ?? [];

    expect(groupKeys($payload))->toBe(['payroll'])
        ->and($result['href'] ?? null)->toBe(route('payroll.show', $period))
        ->and($result['subtitle'] ?? '')->toContain('Draft')
        ->and(json_encode($result))->not->toContain('total')
        ->and(count(groupResults($payload, 'payroll')))->toBe(1);
});

test('crew timesheet viewers can search payroll periods', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    PayrollPeriod::factory()->create([
        'company_id' => $company->id,
        'name' => 'Timesheet Viewer Period',
    ]);
    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    expect(groupKeys(searchAs($user, $company->id, 'Timesheet Viewer')->assertOk()->json()))
        ->toBe(['payroll']);
});

test('soft-deleted employees are excluded', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['name' => 'Deleted Search Person']);
    $employee->delete();
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, 'Deleted Search')->assertOk()->assertJsonPath('groups', []);
});

test('users without payroll capability receive no payroll group', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    PayrollPeriod::factory()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Period Search',
    ]);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $payload = searchAs($user, $company->id, 'Hidden Period')->assertOk()->json();

    expect(groupKeys($payload))->not->toContain('payroll');
});

test('mixed queries return grouped results capped per category', function () {
    $user = User::factory()->create();
    ['company' => $company, 'branch' => $branch, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view', 'departments.view']);

    Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Alpha Mixed Dept',
        'code' => 'AMD',
        'status' => 'active',
    ]);
    $employee->update(['name' => 'Alpha Mixed Person']);

    foreach (range(1, 8) as $i) {
        Employee::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'employee_no' => 'MIX-'.$i,
            'name' => "Alpha Mixed Extra {$i}",
            'status' => 'active',
        ]);
    }

    $payload = searchAs($user, $company->id, 'Alpha Mixed')->assertOk()->json();

    expect(groupKeys($payload))->toEqualCanonicalizing(['employees', 'departments'])
        ->and(count(groupResults($payload, 'employees')))->toBe(GlobalSearchQuery::PER_CATEGORY_LIMIT)
        ->and(count(groupResults($payload, 'departments')))->toBe(1);
});

test('platform access does not grant tenant record search', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['name' => 'Platform Hidden']);
    grantCompanyPermissions($user, $company, []);

    $payload = searchAs($user, $company->id, 'Platform Hidden')->assertOk()->json();

    expect($payload['groups'] ?? [])->toBe([]);
});

test('dual-company users only search the active company', function () {
    $user = User::factory()->create();
    ['company' => $companyA, 'employee' => $employeeA] = makeDocumentFixtures();
    ['company' => $companyB, 'employee' => $employeeB] = makeDocumentFixtures();
    $employeeA->update(['name' => 'UniqueAlphaPerson']);
    $employeeB->update(['name' => 'UniqueBravoPerson']);

    grantCompanyPermissions($user, $companyA, ['employees.view']);
    grantCompanyPermissions($user, $companyB, ['employees.view']);

    $activeA = searchAs($user, $companyA->id, 'UniqueBravo')->assertOk()->json();
    $activeB = searchAs($user, $companyB->id, 'UniqueBravo')->assertOk()->json();

    expect(groupResults($activeA, 'employees'))->toBe([])
        ->and(collect(groupResults($activeB, 'employees'))->pluck('title')->all())
        ->toBe(['UniqueBravoPerson']);
});

test('like metacharacters are treated as literals', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['name' => '100% Club', 'employee_no' => 'EMP-LIT']);
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, '%%')->assertOk()->assertJsonPath('groups', []);
    searchAs($user, $company->id, '__')->assertOk()->assertJsonPath('groups', []);

    expect(collect(groupResults(
        searchAs($user, $company->id, '100%')->assertOk()->json(),
        'employees',
    ))->pluck('title')->all())->toBe(['100% Club']);
});

test('category selectors cannot be supplied by the client', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    searchAs($user, $company->id, 'ab', ['category' => 'employees'])->assertUnprocessable();
});

test('unicode names are searchable', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['name' => 'محمد ربيل']);
    grantCompanyPermissions($user, $company, ['employees.view']);

    expect(collect(groupResults(
        searchAs($user, $company->id, 'محمد')->assertOk()->json(),
        'employees',
    ))->pluck('title')->all())->toBe(['محمد ربيل']);
});

test('global search stays within a bounded number of queries', function () {
    $user = User::factory()->create();
    $home = makeDocumentFixtures();
    grantCompanyPermissions($user, $home['company'], [
        'employees.view',
        'documents.view',
        'crew_operations.assignments.view',
        'crew_operations.vessels.view',
        'departments.view',
        'positions.view',
        'payroll.periods.view',
    ]);

    DB::enableQueryLog();
    searchAs($user, $home['company']->id, 'bound')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(80);
});
