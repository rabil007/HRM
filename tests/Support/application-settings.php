<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

function setupCompanyWithApplicationSettingsPermissions(User $user, array $permissions): void
{
    $country = Country::query()->create([
        'code' => 'APP',
        'name' => 'AppLand',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-app-settings',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, $permissions);
}
