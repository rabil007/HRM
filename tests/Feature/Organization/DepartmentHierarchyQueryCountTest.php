<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Departments\DepartmentHierarchyContext;
use App\Support\Departments\PresentDepartmentEffectiveFields;
use Illuminate\Support\Facades\DB;

test('department effective field presentation stays bounded for large hierarchies', function () {
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
    for ($i = 0; $i < 120; $i++) {
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

    DepartmentHierarchyContext::flush();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $context = DepartmentHierarchyContext::forCompany((int) $company->id);
    $departments = $context->departmentsById;

    foreach ($departments as $department) {
        PresentDepartmentEffectiveFields::forDepartment($department, $departments, (int) $company->id);
    }

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $leaf = $context->present($departments->get($parentId));

    expect($queryCount)->toBeLessThan(25)
        ->and($leaf['manager']['id'] ?? null)->toBe((int) $manager->id)
        ->and($leaf['manager_assignment']['type'])->toBe('inherited');
});
