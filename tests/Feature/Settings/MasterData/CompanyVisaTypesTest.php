<?php

use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access company visa types page', function () {
    $this->get('/settings/master-data/company-visa-types')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete company visa types', function () {
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
        'settings.master-data.company-visa-types.view',
        'settings.master-data.company-visa-types.create',
        'settings.master-data.company-visa-types.update',
        'settings.master-data.company-visa-types.delete',
    ]);

    $this->get('/settings/master-data/company-visa-types')->assertOk();

    $this->post('/settings/master-data/company-visa-types', [
        'name' => 'Company Sponsored',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.company-visa-types.index'));

    $id = CompanyVisaType::query()->where('name', 'Company Sponsored')->value('id');
    expect($id)->not->toBeNull();

    $this->put("/settings/master-data/company-visa-types/{$id}", [
        'name' => 'Group Sponsored',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.company-visa-types.index'));

    $this->assertDatabaseHas('company_visa_types', [
        'id' => $id,
        'name' => 'Group Sponsored',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/company-visa-types/{$id}")
        ->assertRedirect(route('settings.master-data.company-visa-types.index'));

    $this->assertSoftDeleted('company_visa_types', ['id' => $id]);
});
