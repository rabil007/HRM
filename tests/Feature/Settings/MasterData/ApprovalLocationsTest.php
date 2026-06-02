<?php

use App\Models\ApprovalLocation;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access approval locations page', function () {
    $this->get('/settings/master-data/approval-locations')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete approval locations', function () {
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
        'settings.master-data.approval-locations.view',
        'settings.master-data.approval-locations.create',
        'settings.master-data.approval-locations.update',
        'settings.master-data.approval-locations.delete',
    ]);

    $this->get('/settings/master-data/approval-locations')->assertOk();

    $this->post('/settings/master-data/approval-locations', [
        'name' => 'LZ Field',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.approval-locations.index'));

    $id = ApprovalLocation::query()->where('name', 'LZ Field')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/approval-locations/{$id}", [
        'name' => 'Das Island',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.approval-locations.index'));

    $this->assertDatabaseHas('approval_locations', [
        'id' => $id,
        'name' => 'Das Island',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/approval-locations/{$id}")
        ->assertRedirect(route('settings.master-data.approval-locations.index'));

    $this->assertDatabaseMissing('approval_locations', ['id' => $id]);
});
