<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

function setupBulkDocumentsCompany(User $user, array $permissions = []): Company
{
    $country = Country::query()->create([
        'code' => 'BD'.fake()->unique()->numerify('###'),
        'name' => 'Bulk Docs Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BD'.fake()->unique()->numerify('###'),
        'name' => 'Bulk Docs Currency',
        'symbol' => 'AED',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bulk Docs Co',
        'slug' => 'bulk-docs-co-'.fake()->unique()->numerify('###'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, $permissions);

    session(['current_company_id' => $company->id]);

    return $company;
}
