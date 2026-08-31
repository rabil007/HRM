<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @return array{user: User, companyA: Company, companyB: Company}
 */
function makeCompanyAuthorizationPair(): array
{
    $suffix = Str::lower(Str::random(6));
    $countryCode = 'C'.strtoupper(substr($suffix, 0, 2));
    $currencyCode = 'Z'.strtoupper(substr($suffix, 0, 2));

    $country = Country::query()->create([
        'code' => $countryCode,
        'name' => 'Auth Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $currencyCode,
        'name' => 'Auth Currency '.$suffix,
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Alpha Registry '.$suffix,
        'slug' => 'alpha-registry-'.$suffix,
        'industry' => 'Shipping',
        'tax_id' => 'TAX-A-'.$suffix,
        'wps_employer_iban' => 'AE070331234567890123456',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Beta Registry '.$suffix,
        'slug' => 'beta-registry-'.$suffix,
        'industry' => 'Logistics',
        'tax_id' => 'TAX-B-'.$suffix,
        'wps_employer_iban' => 'AE070339999999999999999',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create(['company_id' => null]);

    return compact('user', 'companyA', 'companyB');
}
