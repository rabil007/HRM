<?php

use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\User;
use App\Support\Employees\Services\AdnocSeafarerCvData;
use App\Support\Employees\Services\OffshoreCvData;
use App\Support\SeaServices\SeaServiceDirectoryFilters;
use App\Support\SeaServices\SeaServiceDirectoryQuery;
use App\Support\SeaServices\SeaServiceEmployeeBrowseQuery;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     employee: Employee,
 *     expected_ids: list<int>,
 *     expected_vessels: list<string>
 * }
 */
function makeLatestFirstSeaServiceFixture(): array
{
    $employee = Employee::factory()->create([
        'status' => 'active',
    ]);

    $older = EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-01',
            'sort_order' => 0,
            'total_months' => 5,
            'total_days' => 0,
        ]);

    $newer = EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'sort_order' => 100,
            'total_months' => 5,
            'total_days' => 29,
        ]);

    $current = EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'start_date' => '2026-09-01',
            'end_date' => null,
            'sort_order' => 50,
            'total_months' => 0,
            'total_days' => 0,
        ]);

    $undated = EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'start_date' => null,
            'end_date' => null,
            'sort_order' => -100,
            'total_months' => 0,
            'total_days' => 0,
        ]);

    $ordered = [$current, $newer, $older, $undated];

    return [
        'employee' => $employee,
        'expected_ids' => array_map(
            fn (EmployeeSeaService $service): int => $service->id,
            $ordered,
        ),
        'expected_vessels' => array_map(
            fn (EmployeeSeaService $service): string => (string) $service->vessel()->value('name'),
            $ordered,
        ),
    ];
}

test('sea service read queries use latest effective service date instead of sort order', function () {
    $fixture = makeLatestFirstSeaServiceFixture();
    $employee = $fixture['employee'];
    $companyId = (int) $employee->company_id;

    $scopeIds = EmployeeSeaService::query()
        ->where('company_id', $companyId)
        ->where('employee_id', $employee->id)
        ->latestServiceFirst()
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    $employeeBrowseIds = collect(
        (new SeaServiceEmployeeBrowseQuery)->forEmployee($companyId, $employee)['sea_services'],
    )->pluck('id')->map(fn ($id): int => (int) $id)->all();

    $directoryIds = collect(
        (new SeaServiceDirectoryQuery(
            $companyId,
            new SeaServiceDirectoryFilters,
        ))->paginate(100)->items(),
    )->pluck('id')->map(fn ($id): int => (int) $id)->all();

    expect($scopeIds)
        ->toBe($fixture['expected_ids'])
        ->and($employeeBrowseIds)
        ->toBe($fixture['expected_ids'])
        ->and($directoryIds)
        ->toBe($fixture['expected_ids']);
});

test('employee profile and generated cvs show latest sea service first', function () {
    $fixture = makeLatestFirstSeaServiceFixture();
    $employee = $fixture['employee'];
    $companyId = (int) $employee->company_id;

    $user = User::factory()->create();
    grantCompanyPermissions($user, $employee->company, [
        'employees.view',
        'sea_services.view',
    ]);

    $this->actingAs($user);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->reloadOnly(
                ['sea_services'],
                fn (Assert $reloaded) => $reloaded
                    ->has('sea_services', 4)
                    ->where('sea_services.0.id', $fixture['expected_ids'][0])
                    ->where('sea_services.1.id', $fixture['expected_ids'][1])
                    ->where('sea_services.2.id', $fixture['expected_ids'][2])
                    ->where('sea_services.3.id', $fixture['expected_ids'][3]),
            ));

    $adnoc = AdnocSeafarerCvData::for($employee, $companyId);
    $offshore = OffshoreCvData::for($employee, $companyId);

    expect(collect($adnoc['sea_services'])->pluck('vessel_name')->all())
        ->toBe($fixture['expected_vessels'])
        ->and(collect($offshore['offshore_projects'])->pluck('vessel_name')->all())
        ->toBe($fixture['expected_vessels']);
});
