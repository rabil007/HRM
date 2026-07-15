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

function makeSeaServicesExportFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'SSE'],
        ['name' => 'Sea Service Export Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'SSE'],
        ['name' => 'Sea Service Export Currency', 'symbol' => 'E$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'SeaServiceExportCo',
        'slug' => 'seaserviceexportco-'.uniqid(),
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
        'employee_no' => 'SSE001',
        'name' => 'Export Seafarer',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Export Type '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Export '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Export Rank '.uniqid(),
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
        'is_offshore' => true,
        'sort_order' => 0,
    ]);

    return compact('company', 'branch', 'employee', 'vessel', 'rank', 'seaService');
}

test('guests cannot access sea services export', function () {
    $this->get(route('organization.sea-services.export'))->assertRedirect(route('login'));
});

test('users without sea services view cannot export sea services', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.sea-services.export'))->assertForbidden();
});

test('authenticated users with permission can export sea services as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->get(route('organization.sea-services.export', ['format' => 'csv']))->assertOk();
    $this->get(route('organization.sea-services.export', ['format' => 'xlsx']))->assertOk();
    $this->get(route('organization.sea-services.export', ['format' => 'pdf']))->assertOk();
});

test('sea services export respects offshore filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->get(route('organization.sea-services.export', [
        'format' => 'csv',
        'offshore' => 'offshore',
    ]))->assertOk();
});
