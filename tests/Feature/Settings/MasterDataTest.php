<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access master data pages', function () {
    $this->get('/settings/master-data/countries')->assertRedirect(route('login'));
    $this->get('/settings/master-data/currencies')->assertRedirect(route('login'));
});

test('authenticated users can view countries and currencies pages', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/settings/master-data/countries')->assertOk();
    $this->get('/settings/master-data/currencies')->assertOk();
});

test('authenticated users can create and update a country', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/settings/master-data/countries', [
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
    ])->assertRedirect('/settings/master-data/countries');

    $countryId = Country::query()->where('code', 'TST')->value('id');
    expect($countryId)->not->toBeNull();

    $this->put("/settings/master-data/countries/{$countryId}", [
        'code' => 'TST',
        'name' => 'Testland Updated',
        'dial_code' => '+999',
        'is_active' => true,
    ])->assertRedirect('/settings/master-data/countries');

    $this->assertDatabaseHas('countries', [
        'id' => $countryId,
        'name' => 'Testland Updated',
    ]);
});

test('authenticated users can create and update a currency', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/settings/master-data/currencies', [
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'precision' => 2,
    ])->assertRedirect('/settings/master-data/currencies');

    $currencyId = Currency::query()->where('code', 'TST')->value('id');
    expect($currencyId)->not->toBeNull();

    $this->put("/settings/master-data/currencies/{$currencyId}", [
        'code' => 'TST',
        'name' => 'Test Currency Updated',
        'symbol' => 'T$',
        'precision' => 2,
        'is_active' => true,
    ])->assertRedirect('/settings/master-data/currencies');

    $this->assertDatabaseHas('currencies', [
        'id' => $currencyId,
        'name' => 'Test Currency Updated',
    ]);
});

