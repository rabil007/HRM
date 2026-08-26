<?php

use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

function makeDirectoryCompletenessFixtures(): array
{
    ['user' => $user, 'companyA' => $company, 'companyB' => $otherCompany] = makeCompanyAuthorizationPair();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crewing',
        'code' => 'CRW',
        'status' => 'active',
    ]);

    $country = Country::query()->create([
        'code' => 'EID',
        'name' => 'Presence Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'ABP',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    return compact('user', 'company', 'otherCompany', 'department', 'country', 'rank');
}

function visitEmployeesWithCompleteness(array $fixtures, array $query = [])
{
    return test()->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.employees', $query));
}

test('legacy emirates id presence query params map onto generic completeness', function () {
    expect(EmployeeDirectoryFilters::fromArray(['emirates_id_presence' => 'missing'])->missingFields)
        ->toBe('emirates_id')
        ->and(EmployeeDirectoryFilters::fromArray(['emirates_id_presence' => 'present'])->toQueryArray())
        ->toBe(['present_fields' => 'emirates_id'])
        ->and(EmployeeDirectoryFilters::fromRequest(
            Request::create('/organization/employees', 'GET', ['emirates_id_presence' => 'missing']),
        )->missingFields)->toBe('emirates_id');
});

test('missing emirates id matches null empty and whitespace values', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $nullId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Null Emirates',
        'emirates_id' => null,
        'status' => 'active',
        'work_email' => 'a@example.test',
        'personal_email' => 'b@example.test',
    ]);
    $emptyId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Empty Emirates',
        'emirates_id' => '',
        'status' => 'active',
        'work_email' => 'a@example.test',
        'personal_email' => 'b@example.test',
    ]);
    $whitespaceId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Whitespace Emirates',
        'emirates_id' => '   ',
        'status' => 'active',
        'work_email' => 'a@example.test',
        'personal_email' => 'b@example.test',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Filled Emirates',
        'emirates_id' => '784-1234-1234567-1',
        'status' => 'active',
        'work_email' => 'a@example.test',
        'personal_email' => 'b@example.test',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'emirates_id'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 3)
            ->where('filters.missing_fields', 'emirates_id')
            ->where('employees.0.id', $emptyId->id)
            ->where('employees.1.id', $nullId->id)
            ->where('employees.2.id', $whitespaceId->id));
});

test('present emirates id matches filled values and excludes blanks', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $filled = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Emirates',
        'emirates_id' => '784-1234-1234567-1',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Null Emirates Present',
        'emirates_id' => null,
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Empty Emirates Present',
        'emirates_id' => '',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Whitespace Emirates Present',
        'emirates_id' => '   ',
        'status' => 'active',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['present_fields' => 'emirates_id'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $filled->id)
            ->where('filters.present_fields', 'emirates_id'));
});

test('legacy emirates id presence query still filters the directory', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $own = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Own Missing',
        'emirates_id' => null,
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['otherCompany'])->create([
        'name' => 'Other Missing',
        'emirates_id' => null,
        'status' => 'active',
    ]);

    $response = visitEmployeesWithCompleteness($fixtures, ['emirates_id_presence' => 'missing'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $own->id)
            ->where('filters.missing_fields', 'emirates_id'));

    expect($response->getContent())->not->toContain('Other Missing');
});

test('composite email missing requires both work and personal email to be absent', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $missingBoth = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'No Emails',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => '  ',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Work Email',
        'status' => 'active',
        'work_email' => 'work@example.test',
        'personal_email' => null,
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Personal Email',
        'status' => 'active',
        'work_email' => '',
        'personal_email' => 'personal@example.test',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'email'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $missingBoth->id));
});

test('composite email present matches either work or personal email', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $work = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Work Only',
        'status' => 'active',
        'work_email' => 'work@example.test',
        'personal_email' => null,
    ]);
    $personal = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Personal Only',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => 'personal@example.test',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Neither Email',
        'status' => 'active',
        'work_email' => '',
        'personal_email' => null,
    ]);

    visitEmployeesWithCompleteness($fixtures, ['present_fields' => 'email'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('employees.0.id', $personal->id)
            ->where('employees.1.id', $work->id));
});

test('work and personal email completeness are independent', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $missingWork = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Missing Work',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => 'personal@example.test',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Work',
        'status' => 'active',
        'work_email' => 'work@example.test',
        'personal_email' => null,
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'work_email'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $missingWork->id));
});

test('phone nationality dob and passport completeness use the correct strategy', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $match = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Incomplete Record',
        'status' => 'active',
        'phone' => '  ',
        'nationality_id' => null,
        'date_of_birth' => null,
        'passport_number' => '',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Complete Record',
        'status' => 'active',
        'phone' => '0500000000',
        'nationality_id' => $fixtures['country']->id,
        'date_of_birth' => '1990-01-01',
        'passport_number' => 'A1234567',
    ]);

    visitEmployeesWithCompleteness($fixtures, [
        'missing_fields' => 'phone,nationality,date_of_birth,passport_number',
    ])->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $match->id)
            ->where('filters.missing_fields', 'nationality,passport_number,phone,date_of_birth'));
});

test('unknown completeness keys fail closed and do not broaden results', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Should Not Broaden',
        'emirates_id' => '784-1234-1234567-1',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Also Hidden',
        'emirates_id' => null,
        'status' => 'active',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'salary,iban'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0));
});

test('malformed completeness array query values fail closed', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Should Not Leak',
        'status' => 'active',
        'work_email' => 'keep@example.test',
        'personal_email' => null,
    ]);
    Employee::factory()->forCompany($fixtures['otherCompany'])->create([
        'name' => 'Other Tenant',
        'status' => 'active',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => ['salary']])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->where('filters.missing_fields', '_invalid'));

    visitEmployeesWithCompleteness($fixtures, ['present_fields' => ['email']])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->where('filters.present_fields', '_invalid'));

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => ['email' => 'salary']])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0));
});

test('valid completeness csv still matches employees in the current company', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $match = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Missing Email And Dob',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => null,
        'date_of_birth' => null,
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Email And Dob',
        'status' => 'active',
        'work_email' => 'has@example.test',
        'personal_email' => null,
        'date_of_birth' => '1990-01-01',
    ]);
    Employee::factory()->forCompany($fixtures['otherCompany'])->create([
        'name' => 'Other Company Missing',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => null,
        'date_of_birth' => null,
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'email,date_of_birth'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $match->id)
            ->where('filters.missing_fields', 'email,date_of_birth'));
});

test('unsupported legacy emirates id presence values match no employees', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Should Not Broaden',
        'emirates_id' => '784-1234-1234567-1',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Also Hidden',
        'emirates_id' => null,
        'status' => 'active',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['emirates_id_presence' => '784-1234-1234567-1'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->where('filters.missing_fields', '_invalid'));
});

test('blank status defaults to active and all omits the hr status predicate', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    $active = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Active Person',
        'status' => 'active',
        'work_email' => null,
        'personal_email' => null,
    ]);
    $inactive = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Inactive Person',
        'status' => 'inactive',
        'work_email' => null,
        'personal_email' => null,
    ]);

    visitEmployeesWithCompleteness($fixtures, ['missing_fields' => 'email'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $active->id)
            ->where('filters.status', ''));

    visitEmployeesWithCompleteness($fixtures, ['status' => 'all', 'missing_fields' => 'email'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('employees.0.id', $active->id)
            ->where('employees.1.id', $inactive->id)
            ->where('filters.status', 'all'));
});

test('invalid status values fail closed', function () {
    $fixtures = makeDirectoryCompletenessFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Visible Otherwise',
        'status' => 'active',
    ]);

    visitEmployeesWithCompleteness($fixtures, ['status' => 'not-a-status'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('employees', 0));
});
