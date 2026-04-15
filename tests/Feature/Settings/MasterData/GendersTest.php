<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Gender;
use App\Models\User;

test('guests cannot access genders page', function () {
    $this->get('/settings/master-data/genders')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete genders', function () {
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
        'settings.master-data.genders.view',
        'settings.master-data.genders.create',
        'settings.master-data.genders.update',
        'settings.master-data.genders.delete',
    ]);

    $this->get('/settings/master-data/genders')->assertOk();

    $this->post('/settings/master-data/genders', [
        'name' => 'Male',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.genders.index'));

    $id = Gender::query()->where('name', 'Male')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/genders/{$id}", [
        'name' => 'Other',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.genders.index'));

    $this->assertDatabaseHas('genders', [
        'id' => $id,
        'name' => 'Other',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/genders/{$id}")
        ->assertRedirect(route('settings.master-data.genders.index'));

    $this->assertDatabaseMissing('genders', ['id' => $id]);
});
