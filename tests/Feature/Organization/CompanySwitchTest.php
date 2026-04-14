<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('current company id is set in session from membership', function () {
    $user = User::factory()->create([
        'company_id' => null,
    ]);

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

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSessionHas('current_company_id', $company->id);
});

test('user can switch current company only to a member company', function () {
    $user = User::factory()->create([
        'company_id' => null,
    ]);

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

    $companyA = Company::query()->create([
        'name' => 'Company A',
        'slug' => 'company-a',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Company B',
        'slug' => 'company-b',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $companyA->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->post('/organization/companies/switch', [
        'company_id' => $companyA->id,
    ])->assertRedirect();

    $this->assertSame($companyA->id, session('current_company_id'));

    $this->post('/organization/companies/switch', [
        'company_id' => $companyB->id,
    ])->assertForbidden();
});
