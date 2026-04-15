<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access banks page', function () {
    $this->get('/settings/master-data/banks')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete banks', function () {
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
        'settings.master-data.banks.view',
        'settings.master-data.banks.create',
        'settings.master-data.banks.update',
        'settings.master-data.banks.delete',
    ]);

    $this->get('/settings/master-data/banks')->assertOk();

    $this->post('/settings/master-data/banks', [
        'name' => 'Test Bank',
        'uae_routing_code_agent_id' => '123',
        'country_id' => $country->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.banks.index'));

    $id = Bank::query()->where('name', 'Test Bank')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/banks/{$id}", [
        'name' => 'Test Bank Updated',
        'uae_routing_code_agent_id' => '123',
        'country_id' => $country->id,
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.banks.index'));

    $this->assertDatabaseHas('banks', [
        'id' => $id,
        'name' => 'Test Bank Updated',
        'country_id' => $country->id,
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/banks/{$id}")
        ->assertRedirect(route('settings.master-data.banks.index'));

    $this->assertDatabaseMissing('banks', ['id' => $id]);
});
