<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;

test('guests cannot access settings vessels page', function () {
    $this->get('/settings/master-data/vessels')->assertRedirect(route('login'));
});

test('settings master-data vessels routes redirect to organization vessels', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VRD',
        'name' => 'Vessel Redirect Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VRD',
        'name' => 'Vessel Redirect Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Redirect Co',
        'slug' => 'vessel-redirect-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Redirect Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.view',
        'settings.master-data.vessels.create',
        'settings.master-data.vessels.update',
        'settings.master-data.vessels.delete',
    ]);

    $this->get('/settings/master-data/vessels')
        ->assertRedirect(route('organization.vessels.index'));

    $this->get("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('organization.vessels.show', $vessel));

    $this->get('/settings/master-data/vessels/import/template')
        ->assertRedirect(route('organization.vessels.import.template'));

    $this->post('/settings/master-data/vessels', [
        'name' => 'Ignored',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseMissing('vessels', ['name' => 'Ignored']);

    $this->put("/settings/master-data/vessels/{$vessel->id}", [
        'name' => 'Still Redirect Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])->assertRedirect(route('organization.vessels.show', $vessel));

    $this->assertDatabaseHas('vessels', [
        'id' => $vessel->id,
        'name' => 'Redirect Vessel',
    ]);

    $this->delete("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('organization.vessels.index'));

    expect(Vessel::query()->find($vessel->id))->not->toBeNull();
});

test('guests cannot view settings vessel details page', function () {
    $country = Country::query()->create([
        'code' => 'VGS',
        'name' => 'Vessel Guest Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VGS',
        'name' => 'Vessel Guest Currency',
        'symbol' => 'G$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Guest Co',
        'slug' => 'vessel-guest-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Guest Type',
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Guest Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->get("/settings/master-data/vessels/{$vessel->id}")
        ->assertRedirect(route('login'));
});
