<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use Illuminate\Support\Str;

function createAppRefreshCompany(string $name = 'Acme'): Company
{
    $suffix = Str::lower(Str::random(6));
    $countryCode = 'R'.strtoupper(substr($suffix, 0, 2));
    $currencyCode = 'Y'.strtoupper(substr($suffix, 0, 2));

    $country = Country::query()->create([
        'code' => $countryCode,
        'name' => 'Refresh Land '.$suffix,
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $currencyCode,
        'name' => 'Refresh Currency '.$suffix,
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => $name.' '.$suffix,
        'slug' => Str::slug($name).'-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}
