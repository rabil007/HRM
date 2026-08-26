<?php

use App\Models\Country;
use App\Models\Department;
use App\Models\Position;
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
