<?php

use App\Models\ApprovalLocation;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Rank;
use App\Models\SssaOption;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeSmartSearchResolver;
use Illuminate\Support\Facades\DB;

function makeResolverFixtures(): array
{
    ['user' => $user, 'companyA' => $company, 'companyB' => $otherCompany] = makeCompanyAuthorizationPair();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crewing',
        'code' => 'CRW',
        'status' => 'active',
    ]);

    $otherDepartment = Department::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Crewing',
        'code' => 'CRW',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Electrician',
        'status' => 'active',
    ]);

    $india = Country::query()->create([
        'code' => 'IND',
        'name' => 'India',
        'dial_code' => '+91',
        'is_active' => true,
    ]);

    return compact('user', 'company', 'otherCompany', 'department', 'otherDepartment', 'position', 'india');
}

test('status-only resolution does not query unused master data tables', function () {
    $fixtures = makeResolverFixtures();

    DB::flushQueryLog();
    DB::enableQueryLog();

    (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [[
            'concept' => 'status',
            'operator' => 'equals',
            'value' => 'active',
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' | ');

    expect($sql)->not->toContain('departments')
        ->and($sql)->not->toContain('positions')
        ->and($sql)->not->toContain('countries')
        ->and($sql)->not->toContain('ranks');
});

test('equals plus missing for the same concept is a conflict', function () {
    $fixtures = makeResolverFixtures();

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'department', 'operator' => 'equals', 'value' => 'Crewing'],
            ['concept' => 'department', 'operator' => 'missing', 'value' => null],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters)->toBe([])
        ->and($result->applied)->toBe([])
        ->and($result->ambiguous[0]['reason'] ?? null)->toBe('conflict');
});

test('missing plus present for the same concept is a conflict', function () {
    $fixtures = makeResolverFixtures();

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'email', 'operator' => 'missing', 'value' => null],
            ['concept' => 'email', 'operator' => 'present', 'value' => null],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->ambiguous[0]['reason'] ?? null)->toBe('conflict')
        ->and($result->filters)->toBe([]);
});

test('different equals values for a single-valued concept are a conflict', function () {
    $fixtures = makeResolverFixtures();

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'India'],
            ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'Philippines'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters)->toBe([])
        ->and($result->ambiguous[0]['reason'] ?? null)->toBe('multiple_values');
});

test('present is dropped when an equals value is already present', function () {
    $fixtures = makeResolverFixtures();

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'India'],
            ['concept' => 'nationality', 'operator' => 'present', 'value' => null],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters)->toBe(['nationality_id' => (string) $fixtures['india']->id])
        ->and(collect($result->applied)->pluck('key')->all())->toBe(['nationality:equals']);
});

test('country codes resolve nationality without using numeric ids', function () {
    $fixtures = makeResolverFixtures();

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [[
            'concept' => 'nationality',
            'operator' => 'equals',
            'value' => 'IND',
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters['nationality_id'] ?? null)->toBe((string) $fixtures['india']->id)
        ->and($result->applied[0]['value'] ?? null)->toBe('India');
});

test('multi-value approval locations accumulate unique ids in canonical order', function () {
    $fixtures = makeResolverFixtures();
    $abuDhabi = ApprovalLocation::query()->create(['name' => 'Abu Dhabi', 'is_active' => true]);
    $dubai = ApprovalLocation::query()->create(['name' => 'Dubai', 'is_active' => true]);

    $employeeAbuDhabi = Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);
    $employeeAbuDhabi->approvalLocations()->sync([$abuDhabi->id]);
    $employeeDubai = Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);
    $employeeDubai->approvalLocations()->sync([$dubai->id]);
    Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Abu Dhabi'],
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Dubai'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    $expectedCsv = collect([$abuDhabi->id, $dubai->id])->sort()->implode(',');

    expect($result->filters['approval_location_id'] ?? null)->toBe($expectedCsv)
        ->and(collect($result->applied)->pluck('value')->all())->toEqualCanonicalizing(['Abu Dhabi', 'Dubai'])
        ->and($result->unresolved)->toBe([]);

    $matchedIds = (new EmployeeDirectoryQuery(
        $fixtures['company']->id,
        EmployeeDirectoryFilters::fromArray($result->filters),
    ))->base()->pluck('id')->all();

    expect($matchedIds)->toEqualCanonicalizing([$employeeAbuDhabi->id, $employeeDubai->id]);
});

test('duplicate approval location values collapse to one unique id', function () {
    $fixtures = makeResolverFixtures();
    $abuDhabi = ApprovalLocation::query()->create(['name' => 'Abu Dhabi Unique', 'is_active' => true]);

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Abu Dhabi Unique'],
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Abu Dhabi Unique'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters['approval_location_id'] ?? null)->toBe((string) $abuDhabi->id)
        ->and($result->applied)->toHaveCount(1)
        ->and($result->applied[0]['value'] ?? null)->toBe('Abu Dhabi Unique');
});

test('unresolved approval location does not erase a resolved sibling', function () {
    $fixtures = makeResolverFixtures();
    $abuDhabi = ApprovalLocation::query()->create(['name' => 'Abu Dhabi Kept', 'is_active' => true]);

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Abu Dhabi Kept'],
            ['concept' => 'approval_location', 'operator' => 'equals', 'value' => 'Not A Real Location'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($result->filters['approval_location_id'] ?? null)->toBe((string) $abuDhabi->id)
        ->and($result->applied[0]['value'] ?? null)->toBe('Abu Dhabi Kept')
        ->and($result->unresolved[0]['term'] ?? null)->toBe('Not A Real Location');
});

test('multi-value sssa options accumulate unique ids', function () {
    $fixtures = makeResolverFixtures();
    $supply = SssaOption::query()->create(['name' => 'Supply', 'is_active' => true]);
    $dp2 = SssaOption::query()->create(['name' => 'DP2', 'is_active' => true]);

    $employeeSupply = Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);
    $employeeSupply->sssaOptions()->sync([$supply->id]);
    $employeeDp2 = Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);
    $employeeDp2->sssaOptions()->sync([$dp2->id]);
    Employee::factory()->forCompany($fixtures['company'])->create(['status' => 'active']);

    $result = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'Supply'],
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'DP2'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    $expectedCsv = collect([$supply->id, $dp2->id])->sort()->implode(',');

    expect($result->filters['sssa_option_id'] ?? null)->toBe($expectedCsv)
        ->and(collect($result->applied)->pluck('value')->all())->toEqualCanonicalizing(['Supply', 'DP2']);

    $duplicate = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'Supply'],
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'Supply'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($duplicate->filters['sssa_option_id'] ?? null)->toBe((string) $supply->id)
        ->and($duplicate->applied)->toHaveCount(1);

    $partial = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'DP2'],
            ['concept' => 'sssa_option', 'operator' => 'equals', 'value' => 'Unknown SSSA'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($partial->filters['sssa_option_id'] ?? null)->toBe((string) $dp2->id)
        ->and($partial->unresolved[0]['term'] ?? null)->toBe('Unknown SSSA');

    $matchedIds = (new EmployeeDirectoryQuery(
        $fixtures['company']->id,
        EmployeeDirectoryFilters::fromArray($result->filters),
    ))->base()->pluck('id')->all();

    expect($matchedIds)->toEqualCanonicalizing([$employeeSupply->id, $employeeDp2->id]);
});

test('single-valued concepts still reject conflicting equals values', function () {
    $fixtures = makeResolverFixtures();

    Department::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'HR',
        'code' => 'HR',
        'status' => 'active',
    ]);
    Rank::query()->create(['name' => 'Captain Conflict', 'is_active' => true]);
    Rank::query()->create(['name' => 'AB Conflict', 'is_active' => true]);
    Country::query()->create([
        'code' => 'PHL',
        'name' => 'Philippines',
        'dial_code' => '+63',
        'is_active' => true,
    ]);

    $nationality = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'India'],
            ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'Philippines'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);
    $department = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'department', 'operator' => 'equals', 'value' => 'HR'],
            ['concept' => 'department', 'operator' => 'equals', 'value' => 'Crewing'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);
    $rank = (new EmployeeSmartSearchResolver)->resolve($fixtures['company']->id, [
        'criteria' => [
            ['concept' => 'rank', 'operator' => 'equals', 'value' => 'AB Conflict'],
            ['concept' => 'rank', 'operator' => 'equals', 'value' => 'Captain Conflict'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]);

    expect($nationality->filters)->toBe([])
        ->and($nationality->ambiguous[0]['reason'] ?? null)->toBe('multiple_values')
        ->and($department->filters)->toBe([])
        ->and($department->ambiguous[0]['reason'] ?? null)->toBe('multiple_values')
        ->and($rank->filters)->toBe([])
        ->and($rank->ambiguous[0]['reason'] ?? null)->toBe('multiple_values');
});
