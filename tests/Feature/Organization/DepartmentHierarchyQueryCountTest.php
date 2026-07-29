<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Departments\DepartmentHierarchyContext;
use App\Support\Departments\PresentDepartmentEffectiveFields;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company, root: Department, leafId: int, manager: Employee}
 */
function makeDepartmentHierarchyFixtures(int $childCount): array
{
    $country = Country::query()->create([
        'code' => 'DQ'.fake()->unique()->numerify('##'),
        'name' => 'Dept Queryland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'DQ'.fake()->unique()->numerify('##'),
        'name' => 'Dept Query Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Dept Query Co',
        'slug' => 'dq-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $root = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Root',
        'code' => 'ROOT',
        'parent_id' => null,
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $parentId = $root->id;
    for ($i = 0; $i < $childCount; $i++) {
        $department = Department::query()->create([
            'company_id' => $company->id,
            'name' => 'Dept '.$i,
            'code' => 'D'.$i,
            'parent_id' => $parentId,
            'manager_id' => null,
            'status' => 'active',
        ]);
        $parentId = $department->id;
    }

    return [
        'user' => $user,
        'company' => $company,
        'root' => $root,
        'leafId' => $parentId,
        'manager' => $manager,
    ];
}

test('department effective field presentation stays bounded for large hierarchies', function () {
    $fixtures = makeDepartmentHierarchyFixtures(120);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $context = DepartmentHierarchyContext::forCompany((int) $fixtures['company']->id);
    $departments = $context->departmentsById;

    foreach ($departments as $department) {
        PresentDepartmentEffectiveFields::forDepartmentWithContext($department, $context);
    }

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $leaf = $context->present($departments->get($fixtures['leafId']));

    expect($queryCount)->toBeLessThan(25)
        ->and($leaf['manager']['id'] ?? null)->toBe((int) $fixtures['manager']->id)
        ->and($leaf['manager_assignment']['type'])->toBe('inherited');
});

test('separate department hierarchy contexts do not share stale static state', function () {
    $first = makeDepartmentHierarchyFixtures(3);
    $second = makeDepartmentHierarchyFixtures(3);

    $contextA = DepartmentHierarchyContext::forCompany((int) $first['company']->id);
    $contextB = DepartmentHierarchyContext::forCompany((int) $second['company']->id);

    expect($contextA->companyId)->toBe((int) $first['company']->id)
        ->and($contextB->companyId)->toBe((int) $second['company']->id)
        ->and($contextA->departmentsById->keys()->all())
        ->not->toContain($second['root']->id);
});
