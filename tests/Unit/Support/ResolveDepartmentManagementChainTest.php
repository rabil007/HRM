<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Departments\ResolveDepartmentManagementChain;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{company: Company}
 */
function makeManagementChainCompany(): array
{
    $country = Country::query()->create([
        'code' => 'MC'.fake()->unique()->numerify('##'),
        'name' => 'Management Chainland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'MC'.fake()->unique()->numerify('##'),
        'name' => 'Management Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Management Co',
        'slug' => 'mgmt-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['company' => $company];
}

test('management chain walks parent departments and skips duplicate managers', function () {
    ['company' => $company] = makeManagementChainCompany();

    $topManager = Employee::factory()->forCompany($company)->create(['name' => 'Top']);
    $midManager = Employee::factory()->forCompany($company)->create(['name' => 'Mid']);

    $top = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Top',
        'code' => 'TOP',
        'manager_id' => $topManager->id,
        'status' => 'active',
    ]);

    $mid = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Mid',
        'code' => 'MID',
        'parent_id' => $top->id,
        'manager_id' => $midManager->id,
        'status' => 'active',
    ]);

    $leaf = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Leaf',
        'code' => 'LEAF',
        'parent_id' => $mid->id,
        'manager_id' => $midManager->id,
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'department_id' => $leaf->id,
    ]);

    $chain = ResolveDepartmentManagementChain::forEmployee($employee);

    expect($chain)->toHaveCount(2)
        ->and($chain[0]['manager']->id)->toBe($midManager->id)
        ->and($chain[0]['is_direct'])->toBeTrue()
        ->and($chain[1]['manager']->id)->toBe($topManager->id)
        ->and($chain[1]['is_direct'])->toBeFalse();
});

test('effective manager detail reports inherited managers from ancestors', function () {
    ['company' => $company] = makeManagementChainCompany();

    $parentManager = Employee::factory()->forCompany($company)->create(['name' => 'Parent Mgr']);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Parent',
        'code' => 'P',
        'manager_id' => $parentManager->id,
        'status' => 'active',
    ]);

    $child = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Child',
        'code' => 'C',
        'parent_id' => $parent->id,
        'manager_id' => null,
        'status' => 'active',
    ]);

    $detail = ResolveDepartmentManagementChain::effectiveManagerDetail((int) $company->id, (int) $child->id);

    expect($detail)->not->toBeNull()
        ->and($detail['manager']->id)->toBe($parentManager->id)
        ->and($detail['is_direct'])->toBeFalse()
        ->and($detail['is_inherited'])->toBeTrue()
        ->and($detail['source_department']->id)->toBe($parent->id);
});

test('management chain is empty when employee has no department', function () {
    ['company' => $company] = makeManagementChainCompany();

    $employee = Employee::factory()->forCompany($company)->create([
        'department_id' => null,
    ]);

    expect(ResolveDepartmentManagementChain::forEmployee($employee))->toBe([]);
});
