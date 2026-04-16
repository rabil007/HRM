<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;

test('guests cannot access onboarding templates and records', function () {
    $this->get('/onboarding/templates')->assertRedirect(route('login'));
    $this->get('/onboarding/records')->assertRedirect(route('login'));
});

test('authorized users can view onboarding templates and records pages', function () {
    $this->seed(PermissionsSeeder::class);

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
        'onboarding.templates.view',
        'onboarding.records.view',
    ]);

    $this->get('/onboarding/templates')->assertOk();
    $this->get('/onboarding/records')->assertOk();
});
