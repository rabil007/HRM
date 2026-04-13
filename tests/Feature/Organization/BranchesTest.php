<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access branches page', function () {
    $this->get('/organization/branches')->assertRedirect(route('login'));
});

test('authenticated users can view branches page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/organization/branches')->assertOk();
});

test('authenticated users can create, update, and delete a branch', function () {
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
        'fiscal_year_start' => '01-01',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->post('/organization/branches', [
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => '+971',
        'email' => 'hq@acme.test',
        'is_headquarters' => true,
        'status' => 'active',
    ])->assertRedirect('/organization/branches');

    $branchId = Branch::query()->where('company_id', $company->id)->where('code', 'HQ')->value('id');
    expect($branchId)->not->toBeNull();

    $this->put("/organization/branches/{$branchId}", [
        'company_id' => $company->id,
        'name' => 'HQ Updated',
        'code' => 'HQ',
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => '+971',
        'email' => 'hq@acme.test',
        'is_headquarters' => false,
        'status' => 'inactive',
    ])->assertRedirect('/organization/branches');

    $this->assertDatabaseHas('branches', [
        'id' => $branchId,
        'name' => 'HQ Updated',
        'status' => 'inactive',
    ]);

    $this->delete("/organization/branches/{$branchId}")->assertRedirect('/organization/branches');
    $this->assertDatabaseMissing('branches', ['id' => $branchId]);
});
