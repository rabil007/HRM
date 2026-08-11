<?php

use App\Imports\SeaServicesImport;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\SeaServiceDuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('employee sea services no longer have an offshore flag', function () {
    expect(Schema::hasColumn('employee_sea_services', 'is_offshore'))->toBeFalse();
});

test('guests cannot manage sea services', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_id' => 1,
        'rank_id' => 1,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertRedirect(route('login'));

    $this->get(route('organization.employees.sea-services.import.template', $employee))
        ->assertRedirect(route('login'));

    $this->delete(route('organization.employees.sea-services.bulk-destroy', $employee), [
        'sea_service_ids' => [1],
    ])->assertRedirect(route('login'));
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_id' => 1,
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

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Horizon',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_type_id' => $vesselType->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'start_date' => '2020-01-01',
            'end_date' => '2020-06-22',
            'total_months' => $showDuration['months'],
            'total_days' => $showDuration['days'],
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'sea_services.view', 'sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

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

    $vesselAlpha = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Alpha',
        'vessel_type_id' => $vesselA->id,
        'grt' => 1500.5,
        'bhp' => 5000,
        'is_active' => true,
    ]);

    $vesselAlphaPlus = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Alpha Plus',
        'vessel_type_id' => $vesselAPlus->id,
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $vesselA->id,
        'vessel_id' => $vesselAlpha->id,
        'rank_id' => $rankCaptain->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-03-11',
        'client_id' => $clientX->id,
    ])->assertRedirect();

    $row = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    $expectedDuration = SeaServiceDuration::fromDates('2024-01-01', '2024-03-11');

    expect($row)->not->toBeNull();
    expect($row->load('vessel')->vessel?->name)->toBe('MV Alpha');
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
        'vessel_id' => $vesselAlphaPlus->id,
        'rank_id' => $rankCaptain->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-04-02',
    ])->assertRedirect();

    $updatedDuration = SeaServiceDuration::fromDates('2024-01-01', '2024-04-02');

    expect($row->fresh()->vessel_type_id)->toBe($vesselAPlus->id)
        ->and($row->fresh()->load('vessel')->vessel?->name)->toBe('MV Alpha Plus')
        ->and($row->fresh()->total_months)->toBe($updatedDuration['months'])
        ->and($row->fresh()->total_days)->toBe($updatedDuration['days']);

    $ordered = [$second->id, $row->fresh()->id];

    $this->post(route('organization.employees.sea-services.reorder', $employee), [
        'order' => $ordered,
    ])->assertRedirect();

    expect(EmployeeSeaService::query()->find($second->id)->sort_order)->toBe(0)
        ->and(EmployeeSeaService::query()->find($row->fresh()->id)->sort_order)->toBe(1);

    $this->delete(route('organization.employees.sea-services.destroy', [$employee, $row->fresh()]))
        ->assertRedirect();

    $this->assertSoftDeleted('employee_sea_services', ['id' => $row->id]);

    $this->delete(route('organization.employees.sea-services.destroy', [$employee, $second]))->assertRedirect();
});

test('store requires vessel id and rejects inactive vessel type', function () {
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'sea_services.view', 'sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $inactiveVesselType = VesselType::query()->create([
        'name' => 'Inactive Vessel',
        'is_active' => false,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Able Seaman',
        'is_active' => true,
    ]);

    $inactiveTypeVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Inactive Type',
        'vessel_type_id' => $inactiveVesselType->id,
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $inactiveVesselType->id,
        'vessel_id' => $inactiveTypeVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertSessionHasErrors('vessel_type_id');

    $activeVesselType = VesselType::query()->create([
        'name' => 'Active Vessel',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $activeVesselType->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertSessionHasErrors('vessel_id');

    $activeVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Test',
        'vessel_type_id' => $activeVesselType->id,
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $activeVesselType->id,
        'vessel_id' => $activeVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-03-01',
        'end_date' => '2024-01-01',
    ])->assertSessionHasErrors('end_date');
});

test('company A cannot create or update sea service using company B vessel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TSV',
        'name' => 'Testland Sea Vessel Tenant',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TSV',
        'name' => 'Test Currency Sea Vessel Tenant',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Sea Vessel Tenant',
        'slug' => 'acme-sea-vessel-tenant',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Sea Vessel Tenant',
        'slug' => 'other-sea-vessel-tenant',
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
            'employee_no' => 'EMP-SEA-TENANT',
            'name' => 'Tenant Sailor',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'sea_services.view',
        'sea_services.create',
        'sea_services.update',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Tenant Vessel Type',
        'is_active' => true,
    ]);

    $ownVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Own Tenant',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $foreignVessel = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'MV Foreign Tenant',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Tenant Rank',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $foreignVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ])->assertSessionHasErrors('vessel_id');

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    $row = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $ownVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-02-01',
    ]);

    $this->put(route('organization.employees.sea-services.update', [$employee, $row]), [
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $foreignVessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-03-01',
    ])->assertSessionHasErrors('vessel_id');

    expect((int) $row->fresh()->vessel_id)->toBe((int) $ownVessel->id);
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
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'sea_services.view', 'sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $first = EmployeeSeaService::factory()->forEmployee($employee)->create();
    $second = EmployeeSeaService::factory()->forEmployee($employee)->create();

    $this->post(route('organization.employees.sea-services.reorder', $employee), [
        'order' => [$first->id],
    ])->assertStatus(422);
});

test('sea service import appends rows for the employee from the shared template', function () {
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

    Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV North Star',
        'vessel_type_id' => $vesselType->id,
        'grt' => 42000,
        'bhp' => 6500,
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $importDuration = SeaServiceDuration::fromDates('2023-01-01', '2023-09-11');

    $file = makeEmployeeSeaServicesImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => $vesselType->name,
            'vessel' => 'MV North Star',
            'rank' => $rank->name,
            'start_date' => '2023-01-01',
            'end_date' => '2023-09-11',
            'client' => $client->name,
        ],
    ]);

    $this->post(route('organization.employees.sea-services.import', $employee), [
        'file' => $file,
    ])->assertRedirect();

    $importedRow = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();

    expect($importedRow)->not->toBeNull()
        ->and($importedRow->load('vessel')->vessel?->name)->toBe('MV North Star')
        ->and($importedRow->vessel_type_id)->toBe($vesselType->id)
        ->and($importedRow->rank_id)->toBe($rank->id)
        ->and($importedRow->client_id)->toBe($client->id)
        ->and($importedRow->start_date?->toDateString())->toBe('2023-01-01')
        ->and($importedRow->end_date?->toDateString())->toBe('2023-09-11')
        ->and($importedRow->total_months)->toBe($importDuration['months'])
        ->and($importedRow->total_days)->toBe($importDuration['days']);

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('sea service import accepts day-first date formats for multiple rows', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TDF',
        'name' => 'Testland Date Formats',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TDF',
        'name' => 'Test Currency Date Formats',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme Date Formats',
        'slug' => 'acme-date-formats',
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
            'employee_no' => 'EMP-DF-1',
            'name' => 'Date Format Importer',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create(['name' => 'new', 'is_active' => true]);
    Rank::query()->create(['name' => 'rank', 'is_active' => true]);

    foreach (['BES SINCERE', 'CREST MARS', 'BES SAVVY'] as $vesselName) {
        Vessel::query()->create([
            'company_id' => $company->id,
            'name' => $vesselName,
            'vessel_type_id' => $vesselType->id,
            'is_active' => true,
        ]);
    }

    grantCompanyPermissions($user, $company, ['sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $file = makeEmployeeSeaServicesImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => 'new',
            'vessel' => 'BES SINCERE',
            'rank' => 'rank',
            'start_date' => '18/10/2024',
            'end_date' => '23/12/2024',
        ],
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => 'new',
            'vessel' => 'CREST MARS',
            'rank' => 'rank',
            'start_date' => '31/01/2025',
            'end_date' => '25/03/2025',
        ],
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => 'new',
            'vessel' => 'BES SAVVY',
            'rank' => 'rank',
            'start_date' => '3/1/26',
            'end_date' => '10/2/26',
        ],
    ]);

    $this->post(route('organization.employees.sea-services.import', $employee), [
        'file' => $file,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(3);

    $besSincereVesselId = Vessel::query()->where('name', 'BES SINCERE')->value('id');

    expect(
        EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('vessel_id', $besSincereVesselId)
            ->whereDate('start_date', '2024-10-18')
            ->whereDate('end_date', '2024-12-23')
            ->exists(),
    )->toBeTrue();
});

test('sea service import preview marks missing vessel type as invalid', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    $employee->update([
        'employee_no' => 'EMP-PREV-1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    Rank::query()->create([
        'name' => 'Appointed Person',
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'CREST MARS',
        'vessel_type_id' => VesselType::query()->create(['name' => 'Placeholder Type', 'is_active' => true])->id,
        'is_active' => true,
    ]);

    $file = makeEmployeeSeaServicesImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => null,
            'vessel' => 'CREST MARS',
            'rank' => 'Appointed Person',
            'start_date' => '2024-01-01',
            'end_date' => '2024-03-15',
            'client' => 'EL HAIL',
        ],
    ]);

    $this->post(route('organization.employees.sea-services.import.preview', $employee), [
        'file' => $file,
    ])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'vessel_type');

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('sea service import rejects rows for a different employee number', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    $employee->update([
        'employee_no' => 'EMP-SCOPE-1',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create(['name' => 'Tanker', 'is_active' => true]);
    $rank = Rank::query()->create(['name' => 'Chief Officer', 'is_active' => true]);
    Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MT Scope',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['sea_services.import']);

    $file = makeEmployeeSeaServicesImportFile([
        [
            'employee_no' => 'OTHER-999',
            'name' => 'Other',
            'vessel_type' => $vesselType->name,
            'vessel' => 'MT Scope',
            'rank' => $rank->name,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-01',
        ],
    ]);

    $this->post(route('organization.employees.sea-services.import.preview', $employee), [
        'file' => $file,
    ])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJsonPath('rows.0.errors.0.field', 'employee_no');
});

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeEmployeeSeaServicesImportFile(array $rows): UploadedFile
{
    $import = app(SeaServicesImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName());

    foreach ($import->headers() as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = SeaServicesImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['vessel_type'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['vessel'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['rank'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['start_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, $row['end_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['client'] ?? null);

        $rowNumber++;
    }

    $path = storage_path('app/temp/'.uniqid('employee-sea-services-import-test-', true).'.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'sea-services-import.xlsx', null, null, true);
}

test('users with permission can bulk delete sea service records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view', 'sea_services.view', 'sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $rank = Rank::query()->create([
        'name' => 'Chief Officer',
        'is_active' => true,
    ]);

    $first = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'sort_order' => 0,
    ]);

    $second = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'sort_order' => 1,
    ]);

    $third = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
        'sort_order' => 2,
    ]);

    $this->delete(route('organization.employees.sea-services.bulk-destroy', $employee), [
        'sea_service_ids' => [$first->id, $second->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertSoftDeleted('employee_sea_services', ['id' => $first->id]);
    $this->assertSoftDeleted('employee_sea_services', ['id' => $second->id]);
    expect(EmployeeSeaService::query()->whereKey($third->id)->exists())->toBeTrue();
});

test('bulk delete ignores sea service records from another employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP0099',
        'name' => 'Other Sailor',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'sea_services.view', 'sea_services.create', 'sea_services.update', 'sea_services.delete', 'sea_services.import']);

    $rank = Rank::query()->create([
        'name' => 'Master',
        'is_active' => true,
    ]);

    $ownRecord = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'rank_id' => $rank->id,
    ]);

    $otherRecord = EmployeeSeaService::factory()->forEmployee($otherEmployee)->create([
        'rank_id' => $rank->id,
    ]);

    $this->delete(route('organization.employees.sea-services.bulk-destroy', $employee), [
        'sea_service_ids' => [$ownRecord->id, $otherRecord->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertSoftDeleted('employee_sea_services', ['id' => $ownRecord->id]);
    expect(EmployeeSeaService::query()->whereKey($otherRecord->id)->exists())->toBeTrue();
});

test('users without permission cannot bulk delete sea service records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $record = EmployeeSeaService::factory()->forEmployee($employee)->create();

    $this->delete(route('organization.employees.sea-services.bulk-destroy', $employee), [
        'sea_service_ids' => [$record->id],
    ])->assertForbidden();

    expect(EmployeeSeaService::query()->whereKey($record->id)->exists())->toBeTrue();
});
