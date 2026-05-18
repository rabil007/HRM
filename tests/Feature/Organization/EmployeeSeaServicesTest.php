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
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage sea services', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_name' => 'Test Vessel',
        'rank_id' => 1,
        'total_months' => 1,
        'total_days' => 0,
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
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'probation_end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => 1,
        'vessel_name' => 'Test Vessel',
        'rank_id' => 1,
        'total_months' => 1,
        'total_days' => 0,
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
        'probation_end_date' => null,
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

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_type_id' => $vesselType->id,
            'vessel_name' => 'MV Horizon',
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'total_months' => 5,
            'total_days' => 22,
            'is_offshore' => false,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('sea_services', 1)
            ->where('sea_services.0.vessel_type_name', 'BES SINCERE')
            ->where('sea_services.0.vessel_name', 'MV Horizon'));
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
        'probation_end_date' => null,
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
        'total_months' => 2,
        'total_days' => 10,
        'grt' => '1500.5',
        'bhp' => 5000,
        'client_id' => $clientX->id,
        'is_offshore' => true,
    ])->assertRedirect();

    $row = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();

    expect($row)->not->toBeNull();
    expect($row->vessel_name)->toBe('MV Alpha');
    expect($row->is_offshore)->toBeTrue();
    expect($row->client_id)->toBe($clientX->id);

    $second = EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselB->id,
        'rank_id' => $rankCaptain->id,
        'sort_order' => 5,
    ]);

    $this->put(route('organization.employees.sea-services.update', [$employee, $row]), [
        'vessel_type_id' => $vesselAPlus->id,
        'vessel_name' => 'MV Alpha Plus',
        'rank_id' => $rankCaptain->id,
        'total_months' => 3,
        'total_days' => 1,
        'is_offshore' => false,
    ])->assertRedirect();

    expect($row->fresh()->vessel_type_id)->toBe($vesselAPlus->id)
        ->and($row->fresh()->vessel_name)->toBe('MV Alpha Plus')
        ->and((string) $row->fresh()->total_months)->toBe('3')
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
        'probation_end_date' => null,
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
        'total_months' => 1,
        'total_days' => 0,
    ])->assertSessionHasErrors('vessel_type_id');

    $activeVessel = VesselType::query()->create([
        'name' => 'Active Vessel',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.sea-services.store', $employee), [
        'vessel_type_id' => $activeVessel->id,
        'rank_id' => $rank->id,
        'total_months' => 1,
        'total_days' => 0,
    ])->assertSessionHasErrors('vessel_name');
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
        'probation_end_date' => null,
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
