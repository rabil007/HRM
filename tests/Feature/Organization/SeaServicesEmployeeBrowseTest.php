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

function makeSeaServicesBrowseFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'SSB'],
        ['name' => 'Sea Service Browse Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'SSB'],
        ['name' => 'Sea Service Browse Currency', 'symbol' => 'B$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'SeaServiceBrowseCo',
        'slug' => 'seaservicebrowseco-'.uniqid(),
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
        'employee_no' => 'SSB001',
        'name' => 'Browse Seafarer',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Browse Type '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Browse '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Browse Rank '.uniqid(),
        'is_active' => true,
    ]);

    return compact('company', 'branch', 'employee', 'vesselType', 'vessel', 'rank');
}

test('guests cannot access employee sea services browse page', function () {
    ['employee' => $employee] = makeSeaServicesBrowseFixtures();

    $this->get(route('organization.sea-services.employee', $employee))
        ->assertRedirect(route('login'));
});

test('users without sea services view cannot access employee sea services browse page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeSeaServicesBrowseFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.sea-services.employee', $employee))->assertForbidden();
});

test('employee sea services browse page loads records for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesBrowseFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.create']);

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
        'is_offshore' => false,
        'sort_order' => 0,
    ]);

    $this->get(route('organization.sea-services.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/sea-services/employee')
            ->where('employee.id', $employee->id)
            ->where('employee.name', 'Browse Seafarer')
            ->has('sea_services', 1)
            ->where('sea_services.0.vessel_name', $vessel->name)
            ->where('can.view', true)
            ->where('can.create', true));
});

test('employee sea services browse returns not found for employee in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesBrowseFixtures();
    $other = makeSeaServicesBrowseFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->get(route('organization.sea-services.employee', $other['employee']))
        ->assertNotFound();
});
