<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\VisaType;

test('guests cannot access visa types page', function () {
    $this->get('/settings/master-data/visa-types')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete visa types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.view',
        'settings.master-data.visa-types.create',
        'settings.master-data.visa-types.update',
        'settings.master-data.visa-types.delete',
    ]);

    $this->get('/settings/master-data/visa-types')->assertOk();

    $this->post('/settings/master-data/visa-types', [
        'name' => 'Residential Visa',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.visa-types.index'));

    $id = VisaType::query()->where('name', 'Residential Visa')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/visa-types/{$id}", [
        'name' => 'Mission Visa',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.visa-types.index'));

    $this->assertDatabaseHas('visa_types', [
        'id' => $id,
        'name' => 'Mission Visa',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/visa-types/{$id}")
        ->assertRedirect(route('settings.master-data.visa-types.index'));

    $this->assertDatabaseMissing('visa_types', ['id' => $id]);
});
