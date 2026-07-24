<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\SeaServiceDuration;
use Inertia\Testing\AssertableInertia as Assert;

function makeSeaServicesIndexFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'SSI'],
        ['name' => 'Sea Service Index Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'SSI'],
        ['name' => 'Sea Service Index Currency', 'symbol' => 'S$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'SeaServiceIndexCo',
        'slug' => 'seaserviceindexco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'SSI001',
        'name' => 'Index Seafarer',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Tanker '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Index '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Chief Officer '.uniqid(),
        'is_active' => true,
    ]);

    return compact('company', 'branch', 'employee', 'vesselType', 'vessel', 'rank');
}

test('guests cannot access sea services index', function () {
    $this->get(route('organization.sea-services'))->assertRedirect(route('login'));
});

test('users without sea services view cannot access sea services module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesIndexFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.sea-services'))->assertForbidden();
});

test('sea services index returns paginated records with summary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesIndexFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-06-30');

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2023-01-01',
        'end_date' => '2023-06-30',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    $this->get(route('organization.sea-services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/sea-services/index')
            ->where('summary.total', 1)
            ->where('summary.active', 0)
            ->has('sea_services', 1)
            ->where('sea_services.0.employee_name', 'Index Seafarer')
            ->where('sea_services.0.vessel_name', $vessel->name)
            ->missing('sea_services.0.is_offshore')
            ->where('can.view', true)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false));
});

test('sea services index filters open-ended records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesIndexFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-03-01');

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2023-01-01',
        'end_date' => null,
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2022-01-01',
        'end_date' => '2022-03-01',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 1,
    ]);

    $this->get(route('organization.sea-services', ['active' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/sea-services/index')
            ->has('sea_services', 1)
            ->where('sea_services.0.end_date', null)
            ->where('summary.total', 1));
});

test('sea services index does not leak other company records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesIndexFixtures();

    $other = makeSeaServicesIndexFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-03-01');

    EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2023-01-01',
        'end_date' => '2023-03-01',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    EmployeeSeaService::query()->create([
        'company_id' => $other['company']->id,
        'employee_id' => $other['employee']->id,
        'vessel_type_id' => $other['vesselType']->id,
        'vessel_id' => $other['vessel']->id,
        'rank_id' => $other['rank']->id,
        'start_date' => '2023-01-01',
        'end_date' => '2023-03-01',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    $this->get(route('organization.sea-services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('sea_services', 1)
            ->where('sea_services.0.employee_id', $employee->id)
            ->where('summary.total', 1));
});
