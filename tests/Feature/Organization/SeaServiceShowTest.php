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

function makeSeaServiceShowFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'SSS'],
        ['name' => 'Sea Service Show Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'SSS'],
        ['name' => 'Sea Service Show Currency', 'symbol' => 'H$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'SeaServiceShowCo',
        'slug' => 'seaserviceshowco-'.uniqid(),
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
        'employee_no' => 'SSS001',
        'name' => 'Show Seafarer',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Show Type '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Show '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Show Rank '.uniqid(),
        'is_active' => true,
    ]);

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-06-30');

    $seaService = EmployeeSeaService::query()->create([
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

    return compact('company', 'employee', 'seaService', 'vessel');
}

test('guests cannot access sea service show page', function () {
    ['seaService' => $seaService] = makeSeaServiceShowFixtures();

    $this->get(route('organization.sea-services.show', $seaService))
        ->assertRedirect(route('login'));
});

test('users without sea services view cannot access sea service show page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'seaService' => $seaService] = makeSeaServiceShowFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.sea-services.show', $seaService))->assertForbidden();
});

test('authorized user can load sea service show page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'seaService' => $seaService, 'vessel' => $vessel] = makeSeaServiceShowFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.delete']);

    $this->get(route('organization.sea-services.show', $seaService))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/sea-services/show')
            ->where('sea_service.id', $seaService->id)
            ->where('sea_service.vessel_name', $vessel->name)
            ->where('employee.id', $employee->id)
            ->where('can.view', true)
            ->where('can.delete', true)
            ->where('can_view_audit', false));
});

test('sea service show returns not found for record in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServiceShowFixtures();
    $other = makeSeaServiceShowFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->get(route('organization.sea-services.show', $other['seaService']))
        ->assertNotFound();
});
