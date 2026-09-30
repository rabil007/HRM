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
use App\Support\Positions\RankPositionBridge;

function makeSeaServicesExportFixtures(bool $legacyRankOnly = false): array
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
        'company_id' => $company->id,
        'name' => 'MV Export '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Legacy Rank Label '.uniqid(),
        'is_active' => true,
    ]);

    $position = ensureRankMappedPosition($company, $rank);
    $position->update(['title' => 'Canonical Position Title '.$position->id]);
    RankPositionBridge::clearCache();

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-06-30');

    $seaService = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'position_id' => $legacyRankOnly ? null : $position->id,
        'start_date' => '2023-01-01',
        'end_date' => '2023-06-30',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    return compact('company', 'branch', 'employee', 'vessel', 'rank', 'position', 'seaService');
}

test('guests cannot access sea services export', function () {
    $this->get(route('organization.sea-services.export'))->assertRedirect(route('login'));
});

test('users without sea services view cannot export sea services', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, []);

    $this->get(route('organization.sea-services.export'))->assertForbidden();
});

test('authenticated users with permission can export sea services as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'position' => $position, 'rank' => $rank] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    expect($rank->name)->not->toBe($position->title);

    $csv = $this->get(route('organization.sea-services.export', ['format' => 'csv']));
    $csv->assertOk();
    $csvContent = $csv->streamedContent();
    expect($csvContent)
        ->toContain('Position')
        ->not->toContain(',Rank,')
        ->toContain($position->title)
        ->not->toContain($rank->name);

    $xlsx = $this->get(route('organization.sea-services.export', ['format' => 'xlsx']));
    $xlsx->assertOk();

    $pdf = $this->get(route('organization.sea-services.export', ['format' => 'pdf']));
    $pdf->assertOk();
    expect($pdf->headers->get('content-disposition'))->toContain('.pdf');
});

test('sea services pdf export uses position heading and mapped title for legacy rank-only rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'position' => $position, 'rank' => $rank] = makeSeaServicesExportFixtures(legacyRankOnly: true);

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    expect($rank->name)->not->toBe($position->title);

    $html = view('exports.sea-services', [
        'seaServices' => tap(
            EmployeeSeaService::query()->where('company_id', $company->id)->with(['position', 'rank'])->get(),
            fn ($rows) => RankPositionBridge::hydrateCanonicalPositions($rows, (int) $company->id),
        ),
        'generatedAt' => now(),
    ])->render();

    expect($html)
        ->toContain('<th>Position</th>')
        ->not->toContain('<th>Rank</th>')
        ->toContain($position->title)
        ->not->toContain($rank->name);
});

test('sea services export template links assignment phases instead of deployments', function () {
    $html = view('exports.sea-services', [
        'seaServices' => collect(),
        'generatedAt' => now(),
    ])->render();

    expect($html)
        ->toContain('Linked Assignment Phase')
        ->toContain('<th>Position</th>')
        ->not->toContain('<th>Rank</th>')
        ->not->toContain('Linked Deployment')
        ->not->toContain('employee_deployment_id')
        ->not->toContain('>Offshore<');
});

test('sea services export respects active filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeSeaServicesExportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->get(route('organization.sea-services.export', [
        'format' => 'csv',
        'active' => '1',
    ]))->assertOk();
});

test('sea services export can be limited to selected record ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [
        'company' => $company,
        'branch' => $branch,
        'seaService' => $seaService,
    ] = makeSeaServicesExportFixtures();

    $excludedEmployee = Employee::factory()
        ->forCompany($company)
        ->inBranch($branch)
        ->create([
            'employee_no' => 'SSE-EXCLUDED',
            'name' => 'Excluded Sea Service Employee',
        ]);

    EmployeeSeaService::factory()->forEmployee($excludedEmployee)->create();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $content = $this->get(route('organization.sea-services.export', [
        'format' => 'csv',
        'ids' => (string) $seaService->id,
    ]))->streamedContent();

    expect($content)
        ->toContain('Export Seafarer')
        ->not->toContain('Excluded Sea Service Employee');
});
