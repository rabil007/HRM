<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('company membership backfill command inserts company_user rows from users.company_id and is idempotent', function () {
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

    $user = User::factory()->create([
        'company_id' => $company->id,
    ]);

    expect(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(0);

    Artisan::call('app:backfill-company-memberships');

    expect(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(1);

    Artisan::call('app:backfill-company-memberships');

    expect(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->count())->toBe(1);
});
