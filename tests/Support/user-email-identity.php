<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @return array{companyA: Company, companyB: Company}
 */
function makeTwoCompaniesForUserEmailIdentity(string $prefix = 'uei'): array
{
    $suffix = Str::lower(Str::random(6));

    $country = Country::query()->create([
        'code' => strtoupper(substr($suffix, 0, 3)),
        'name' => 'Email Identity Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => strtoupper(substr($suffix, 3, 3)),
        'name' => 'Email Identity Currency '.$suffix,
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Alpha '.$prefix.' '.$suffix,
        'slug' => $prefix.'-a-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Beta '.$prefix.' '.$suffix,
        'slug' => $prefix.'-b-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('companyA', 'companyB');
}

/**
 * @return array{companyA: Company, companyB: Company, userA: User, userB: User, email: string}
 */
function createDuplicateEmailUsers(string $email = 'dup@example.com'): array
{
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity();

    $userA = User::factory()->create([
        'email' => $email,
        'company_id' => $companyA->id,
    ]);
    $userB = User::factory()->create([
        'email' => $email,
        'company_id' => $companyB->id,
    ]);

    return compact('companyA', 'companyB', 'userA', 'userB', 'email');
}
