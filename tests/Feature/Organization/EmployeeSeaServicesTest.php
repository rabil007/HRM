<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\VesselType;
use App\Support\Employees\SeaServiceDuration;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage sea services', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_name' => 'Test Vessel',
        'rank_id' => 1,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertRedirect(route('login'));

    $this->get(route('organization.employees.sea-services.import.template', $employee))
        ->assertRedirect(route('login'));
});

test('users without permission cannot manage sea services', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TS2',
        'name' => 'Testland Sea',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TS2',
        'name' => 'Test Currency Sea',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea',
        'slug' => 'acme-sea',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0002',
            'name' => 'Jane Doe',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_name' => 'Test Vessel',
        'rank_id' => 1,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertForbidden();
});

test('employee show page includes sea services', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TS3',
        'name' => 'Testland Sea Show',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TS3',
        'name' => 'Test Currency Sea Show',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea Show',
        'slug' => 'acme-sea-show',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0003',
            'name' => 'Alex Row',
            'nationality_id' => $country->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'BES SINCERE',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Chief Officer',
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'Berltiz',
        'is_active' => true,
    ]);

    $showDuration = SeaServiceDuration::fromDates('2020-01-01', '2020-06-22');

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_type_id' => $vesselType->id,
            'vessel_name' => 'MV Horizon',
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'start_date' => '2020-01-01',
            'end_date' => '2020-06-22',
            'total_months' => $showDuration['months'],
            'total_days' => $showDuration['days'],
            'is_offshore' => false,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page->component('organization/employee'),
            fn (Assert $page) => $page
                ->has('sea_services', 1)
                ->where('sea_services.0.vessel_type_name', 'BES SINCERE')
                ->where('sea_services.0.vessel_name', 'MV Horizon'),
        ));
});

test('users with permission can add update delete and reorder sea services', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TS4',
        'name' => 'Testland Sea CRUD',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TS4',
        'name' => 'Test Currency Sea CRUD',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea CRUD',
        'slug' => 'acme-sea-crud',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0004',
            'name' => 'Sam Sailor',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.sea_service.manage']);

    $vesselA = VesselType::query()->create([
        'name' => 'Vessel A',
        'is_active' => true,
    ]);

    $vesselB = VesselType::query()->create([
        'name' => 'Vessel B',
        'is_active' => true,
    ]);

    $vesselAPlus = VesselType::query()->create([
        'name' => 'Vessel A+',
        'is_active' => true,
    ]);

    $rankCaptain = Rank::query()->create([
        'name' => 'Captain',
        'is_active' => true,
    ]);

    $clientX = Client::query()->create([
        'name' => 'Client X',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $vesselA->id,
        'vessel_name' => 'MV Alpha',
        'rank_id' => $rankCaptain->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-03-11',
        'grt' => '1500.5',
        'bhp' => 5000,
        'client_id' => $clientX->id,
        'is_offshore' => true,
    ])->assertRedirect();

    $row = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    $expectedDuration = SeaServiceDuration::fromDates('2024-01-01', '2024-03-11');

    expect($row)->not->toBeNull();
    expect($row->vessel_name)->toBe('MV Alpha');
    expect($row->is_offshore)->toBeTrue();
    expect($row->client_id)->toBe($clientX->id);
    expect($row->start_date?->toDateString())->toBe('2024-01-01');
    expect($row->end_date?->toDateString())->toBe('2024-03-11');
    expect($row->total_months)->toBe($expectedDuration['months']);
    expect($row->total_days)->toBe($expectedDuration['days']);

    $second = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselB->id,
        'rank_id' => $rankCaptain->id,
        'sort_order' => 5,
    ]);

    $this->put(route('organization.employees.sea-services.update', [$employee, $row]), [
        'vessel_type_id' => $vesselAPlus->id,
        'vessel_name' => 'MV Alpha Plus',
        'rank_id' => $rankCaptain->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-04-02',
        'is_offshore' => false,
    ])->assertRedirect();

    $updatedDuration = SeaServiceDuration::fromDates('2024-01-01', '2024-04-02');

    expect($row->fresh()->vessel_type_id)->toBe($vesselAPlus->id)
        ->and($row->fresh()->vessel_name)->toBe('MV Alpha Plus')
        ->and($row->fresh()->total_months)->toBe($updatedDuration['months'])
        ->and($row->fresh()->total_days)->toBe($updatedDuration['days'])
        ->and($row->fresh()->is_offshore)->toBeFalse();

    $ordered = [$second->id, $row->fresh()->id];

    $this->post(route('organization.employees.sea-services.reorder', $employee), [
        'order' => $ordered,
    ])->assertRedirect();

    expect(EmployeeSeaService::query()->find($second->id)->sort_order)->toBe(0)
        ->and(EmployeeSeaService::query()->find($row->fresh()->id)->sort_order)->toBe(1);

    $this->delete(route('organization.employees.sea-services.destroy', [$employee, $row->fresh()]))
        ->assertRedirect();

    $this->assertDatabaseMissing('employee_sea_services', ['id' => $row->id]);

    $this->delete(route('organization.employees.sea-services.destroy', [$employee, $second]))->assertRedirect();
});

test('store requires vessel name and rejects inactive vessel type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TS5',
        'name' => 'Testland Sea Validation',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TS5',
        'name' => 'Test Currency Sea Validation',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea Validation',
        'slug' => 'acme-sea-validation',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0005',
            'name' => 'Validation Sailor',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.sea_service.manage']);

    $inactiveVessel = VesselType::query()->create([
        'name' => 'Inactive Vessel',
        'is_active' => false,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Able Seaman',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $inactiveVessel->id,
        'vessel_name' => 'MV Test',
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertSessionHasErrors('vessel_type_id');

    $activeVessel = VesselType::query()->create([
        'name' => 'Active Vessel',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $activeVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertSessionHasErrors('vessel_name');

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $activeVessel->id,
        'vessel_name' => 'MV Test',
        'rank_id' => $rank->id,
        'start_date' => '2024-03-01',
        'end_date' => '2024-01-01',
    ])->assertSessionHasErrors('end_date');
});

test('reorder rejects partial order lists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TS6',
        'name' => 'Testland Sea Reorder',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TS6',
        'name' => 'Test Currency Sea Reorder',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea Reorder',
        'slug' => 'acme-sea-reorder',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0006',
            'name' => 'Reorder Sailor',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.sea_service.manage']);

    $first = EmployeeSeaService::factory()->forEmployee($employee)->create();
    $second = EmployeeSeaService::factory()->forEmployee($employee)->create();

    $this->post(route('organization.employees.sea-services.reorder', $employee), [
        'order' => [$first->id],
    ])->assertStatus(422);
});

test('csv import appends sea service rows for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TSS',
        'name' => 'Testland Sea Import',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TSS',
        'name' => 'Test Currency Sea Import',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea Import',
        'slug' => 'acme-sea-import',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0099',
            'name' => 'Sea Importer',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Bulk Carrier',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Second Officer',
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'Offshore Logistics',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.sea_service.manage']);

    $csv = <<<'CSV'
vessel type,vessel name,rank,start date,end date,grt,bhp,client,is offshore
Bulk Carrier,MV North Star,Second Officer,2023-01-01,2023-09-11,42000,6500,Offshore Logistics,yes

CSV;

    $importDuration = SeaServiceDuration::fromDates('2023-01-01', '2023-09-11');

    $file = UploadedFile::fake()->createWithContent('sea-service.csv', $csv);

    $this->post(route('organization.employees.sea-services.import', $employee), [
        'file' => $file,
    ])->assertRedirect();

    $importedRow = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();

    expect($importedRow)->not->toBeNull()
        ->and($importedRow->vessel_name)->toBe('MV North Star')
        ->and($importedRow->vessel_type_id)->toBe($vesselType->id)
        ->and($importedRow->rank_id)->toBe($rank->id)
        ->and($importedRow->client_id)->toBe($client->id)
        ->and($importedRow->start_date?->toDateString())->toBe('2023-01-01')
        ->and($importedRow->end_date?->toDateString())->toBe('2023-09-11')
        ->and($importedRow->total_months)->toBe($importDuration['months'])
        ->and($importedRow->total_days)->toBe($importDuration['days'])
        ->and($importedRow->is_offshore)->toBeTrue();

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('sea service import returns a clear error when vessel type is missing', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.sea_service.manage']);

    Rank::query()->create([
        'name' => 'Appointed Person',
        'is_active' => true,
    ]);

    $csv = <<<'CSV'
vessel_type,vessel_name,rank,start_date,end_date,grt,bhp,client,is_offshore
,CREST MARS,Appointed Person,2024-01-01,2024-03-15,,,EL HAIL,

CSV;

    $file = UploadedFile::fake()->createWithContent('ashok new.csv', $csv);

    $this->post(route('organization.employees.sea-services.import', $employee), [
        'file' => $file,
    ])->assertSessionHasErrors(['file']);

    expect(session('errors')->get('file')[0])
        ->toContain('missing vessel_type');

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);
});
