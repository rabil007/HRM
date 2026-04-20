<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('shared inertia sidebar props are cached per user and company', function () {
    Cache::flush();

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

    $this->get('/dashboard')->assertOk();

    expect(Cache::has("inertia:shared:{$user->id}:companies"))->toBeTrue();
    expect(Cache::has("inertia:shared:{$user->id}:company:{$company->id}:permissions"))->toBeTrue();
    expect(Cache::has("inertia:shared:{$user->id}:company:{$company->id}:roles"))->toBeTrue();
});

