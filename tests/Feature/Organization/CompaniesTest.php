<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access companies pages', function () {
    $this->get('/organization/companies')->assertRedirect(route('login'));
    $this->get('/organization/companies/1')->assertRedirect(route('login'));
});

test('authenticated users can view company details page', function () {
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

    $this->get("/organization/companies/{$company->id}")->assertOk();
});

test('authenticated users can update a company with all fields', function () {
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

    $this->put("/organization/companies/{$company->id}", [
        'name' => 'Acme Updated',
        'industry' => 'Technology',
        'company_size' => '1-50',
        'registration_number' => 'REG-123',
        'tax_id' => 'TAX-123',
        'country_id' => $country->id,
        'city' => 'Dubai',
        'address' => 'Test address',
        'phone' => '+971555000000',
        'email' => 'hr@acme.test',
        'website' => 'acme.test',
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'weekly',
        'working_days' => [1, 2, 3, 4],
        'wps_agent_code' => 'AGENT-1',
        'wps_mol_uid' => 'MOL-1',
        'status' => 'suspended',
    ])->assertRedirect('/organization/companies');

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'name' => 'Acme Updated',
        'industry' => 'Technology',
        'company_size' => '1-50',
        'registration_number' => 'REG-123',
        'tax_id' => 'TAX-123',
        'city' => 'Dubai',
        'address' => 'Test address',
        'phone' => '+971555000000',
        'email' => 'hr@acme.test',
        'website' => 'acme.test',
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'weekly',
        'wps_agent_code' => 'AGENT-1',
        'wps_mol_uid' => 'MOL-1',
        'status' => 'suspended',
    ]);
});
