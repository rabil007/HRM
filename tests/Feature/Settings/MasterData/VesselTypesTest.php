<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;

test('guests cannot access vessel types page', function () {
    $this->get('/settings/master-data/vessel-types')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete vessel types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSL',
        'name' => 'Vessel Testland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSL',
        'name' => 'Vessel Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Co',
        'slug' => 'vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessel-types.view',
        'settings.master-data.vessel-types.create',
        'settings.master-data.vessel-types.update',
        'settings.master-data.vessel-types.delete',
    ]);

    $this->get('/settings/master-data/vessel-types')->assertOk();

    $this->post('/settings/master-data/vessel-types', [
        'name' => 'OSV Aurora',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessel-types.index'));

    $id = VesselType::query()->where('name', 'OSV Aurora')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/vessel-types/{$id}", [
        'name' => 'OSV Aurora II',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.vessel-types.index'));

    $this->assertDatabaseHas('vessel_types', [
        'id' => $id,
        'name' => 'OSV Aurora II',
    ]);

    $this->delete("/settings/master-data/vessel-types/{$id}")
        ->assertRedirect(route('settings.master-data.vessel-types.index'));

    $this->assertDatabaseMissing('vessel_types', ['id' => $id]);
});

test('authorized users can download template and import vessel types from csv', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VIM',
        'name' => 'Importland',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIM',
        'name' => 'Import Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Co',
        'slug' => 'import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessel-types.view',
        'settings.master-data.vessel-types.create',
    ]);

    $this->get('/settings/master-data/vessel-types/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "name,is_active\nHarbour Star,no\nPacific Runner,yes\n";

    $this->post('/settings/master-data/vessel-types/import', [
        'file' => UploadedFile::fake()->createWithContent('vessel-types.csv', $csvContent),
    ])->assertRedirect(route('settings.master-data.vessel-types.index'));

    expect(VesselType::query()->where('name', 'Harbour Star')->value('is_active'))->toBe(false);
    expect(VesselType::query()->where('name', 'Pacific Runner')->value('is_active'))->toBe(true);
});

test('cannot delete vessel type used on employee sea service records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VSD',
        'name' => 'Vessel Sea Delete',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VSD',
        'name' => 'Vessel Sea Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Sea Delete Co',
        'slug' => 'sea-delete-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessel-types.view',
        'settings.master-data.vessel-types.delete',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'In Use Vessel',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Officer',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0099',
            'name' => 'Sea Worker',
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

    EmployeeSeaService::factory()
        ->forEmployee($employee)
        ->create([
            'vessel_type_id' => $vesselType->id,
            'vessel_name' => 'MV In Use',
            'rank_id' => $rank->id,
        ]);

    $this->from(route('settings.master-data.vessel-types.index'))
        ->delete("/settings/master-data/vessel-types/{$vesselType->id}")
        ->assertRedirect(route('settings.master-data.vessel-types.index'))
        ->assertSessionHasErrors('name');

    expect(VesselType::query()->whereKey($vesselType->id)->exists())->toBeTrue();
});
