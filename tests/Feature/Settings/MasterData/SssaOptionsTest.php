<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\SssaOption;
use App\Models\User;

test('guests cannot access sssa options page', function () {
    $this->get('/settings/master-data/sssa-options')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete sssa options', function () {
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
        'settings.master-data.sssa-options.view',
        'settings.master-data.sssa-options.create',
        'settings.master-data.sssa-options.update',
        'settings.master-data.sssa-options.delete',
    ]);

    $this->get('/settings/master-data/sssa-options')->assertOk();

    $this->post('/settings/master-data/sssa-options', [
        'name' => 'Supply',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.sssa-options.index'));

    $id = SssaOption::query()->where('name', 'Supply')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/sssa-options/{$id}", [
        'name' => 'DP2',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.sssa-options.index'));

    $this->assertDatabaseHas('sssa_options', [
        'id' => $id,
        'name' => 'DP2',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/sssa-options/{$id}")
        ->assertRedirect(route('settings.master-data.sssa-options.index'));

    $this->assertSoftDeleted('sssa_options', ['id' => $id]);
});
