<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

function setupCompanyWithSettingsPermissions(User $user, array $permissions): void
{
    $country = Country::query()->create([
        'code' => 'CMP',
        'name' => 'CompanyLand',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CMP',
        'name' => 'Company Currency',
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

    grantCompanyPermissions($user, $company, $permissions);
}
