<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Religion;
use App\Models\User;

test('guests cannot access religions page', function () {
    $this->get('/settings/master-data/religions')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete religions', function () {
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
        'settings.master-data.religions.view',
        'settings.master-data.religions.create',
        'settings.master-data.religions.update',
        'settings.master-data.religions.delete',
    ]);

    $this->get('/settings/master-data/religions')->assertOk();

    $this->post('/settings/master-data/religions', [
        'name' => 'Muslim',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.religions.index'));

    $id = Religion::query()->where('name', 'Muslim')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/religions/{$id}", [
        'name' => 'Christian',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.religions.index'));

    $this->assertDatabaseHas('religions', [
        'id' => $id,
        'name' => 'Christian',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/religions/{$id}")
        ->assertRedirect(route('settings.master-data.religions.index'));

    $this->assertDatabaseMissing('religions', ['id' => $id]);
});
