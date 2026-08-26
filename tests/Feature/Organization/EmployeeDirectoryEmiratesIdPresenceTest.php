<?php

use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Rank;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

function makeEmiratesIdPresenceFixtures(): array
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

function visitEmployeesWithPresence(array $fixtures, array $query = [])
{
    return test()->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.employees', $query));
}

test('employee directory filters accept emirates id presence missing and present', function () {
    expect(EmployeeDirectoryFilters::fromArray(['emirates_id_presence' => 'missing'])->emiratesIdPresence)
        ->toBe('missing')
        ->and(EmployeeDirectoryFilters::fromArray(['emirates_id_presence' => 'present'])->toQueryArray())
        ->toBe(['emirates_id_presence' => 'present'])
        ->and(EmployeeDirectoryFilters::fromRequest(
            Request::create('/organization/employees', 'GET', ['emirates_id_presence' => 'missing']),
        )->emiratesIdPresence)->toBe('missing');
});

test('missing emirates id presence matches null empty and whitespace values', function () {
    $fixtures = makeEmiratesIdPresenceFixtures();

    $nullId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Null Emirates',
        'emirates_id' => null,
        'status' => 'active',
    ]);
    $emptyId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Empty Emirates',
        'emirates_id' => '',
        'status' => 'active',
    ]);
    $whitespaceId = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Whitespace Emirates',
        'emirates_id' => '   ',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Filled Emirates',
        'emirates_id' => '784-1234-1234567-1',
        'status' => 'active',
    ]);

    visitEmployeesWithPresence($fixtures, ['emirates_id_presence' => 'missing'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 3)
            ->where('filters.emirates_id_presence', 'missing')
            ->where('employees.0.id', $emptyId->id)
            ->where('employees.1.id', $nullId->id)
            ->where('employees.2.id', $whitespaceId->id));
});

test('present emirates id presence matches filled values and excludes blanks', function () {
    $fixtures = makeEmiratesIdPresenceFixtures();

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

    visitEmployeesWithPresence($fixtures, ['emirates_id_presence' => 'present'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $filled->id)
            ->where('filters.emirates_id_presence', 'present'));
});

test('emirates id presence filter remains tenant scoped', function () {
    $fixtures = makeEmiratesIdPresenceFixtures();

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

    $response = visitEmployeesWithPresence($fixtures, ['emirates_id_presence' => 'missing'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $own->id));

    expect($response->getContent())->not->toContain('Other Missing');
});

test('emirates id presence composes with status department nationality and rank', function () {
    $fixtures = makeEmiratesIdPresenceFixtures();

    $match = Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Composed Match',
        'status' => 'active',
        'department_id' => $fixtures['department']->id,
        'nationality_id' => $fixtures['country']->id,
        'rank_id' => $fixtures['rank']->id,
        'emirates_id' => null,
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Wrong Status',
        'status' => 'inactive',
        'department_id' => $fixtures['department']->id,
        'nationality_id' => $fixtures['country']->id,
        'rank_id' => $fixtures['rank']->id,
        'emirates_id' => null,
    ]);
    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Has Id',
        'status' => 'active',
        'department_id' => $fixtures['department']->id,
        'nationality_id' => $fixtures['country']->id,
        'rank_id' => $fixtures['rank']->id,
        'emirates_id' => '784-0000-0000000-0',
    ]);

    visitEmployeesWithPresence($fixtures, [
        'status' => 'active',
        'department_id' => (string) $fixtures['department']->id,
        'nationality_id' => (string) $fixtures['country']->id,
        'rank_id' => (string) $fixtures['rank']->id,
        'emirates_id_presence' => 'missing',
    ])->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $match->id));
});

test('unsupported emirates id presence values match no employees', function () {
    $fixtures = makeEmiratesIdPresenceFixtures();

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

    visitEmployeesWithPresence($fixtures, ['emirates_id_presence' => '784-1234-1234567-1'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 0)
            ->where('filters.emirates_id_presence', '784-1234-1234567-1'));
});
