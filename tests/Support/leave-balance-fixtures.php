<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveBalanceFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LB'.fake()->unique()->numerify('##'),
        'name' => 'Leave Balanceland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LB'.fake()->unique()->numerify('##'),
        'name' => 'Leave Balance Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Balance Co',
        'slug' => 'balance-'.fake()->unique()->numerify('####'),
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

    return ['user' => $user, 'company' => $company];
}
