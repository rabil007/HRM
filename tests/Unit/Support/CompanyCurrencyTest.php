<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Services\Settings\SettingService;
use App\Support\Settings\CompanyCurrency;
use App\Support\Settings\SettingKey;

test('company currency returns company code', function () {
    $company = makeCurrencyCompany('GBP', '£');

    expect(CompanyCurrency::forCompany($company))->toMatchArray([
        'code' => 'GBP',
        'symbol' => '£',
    ]);
});

test('company currency falls back to legacy global then aed', function () {
    $company = makeCurrencyCompany('GBP', '£');
    $company->setRelation('currency', null);

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::Currency],
        ['value' => 'USD', 'type' => 'string'],
    );
    app(SettingService::class)->clearCache();

    expect(CompanyCurrency::codeForCompany($company))->toBe('USD');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::Currency],
        ['value' => '', 'type' => 'string'],
    );
    app(SettingService::class)->clearCache();

    expect(CompanyCurrency::codeForCompany($company))->toBe('AED');
});

function makeCurrencyCompany(string $code, string $symbol): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'CCU'],
        ['name' => 'Currency Unit', 'dial_code' => '+0', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        ['name' => $code, 'symbol' => $symbol, 'is_active' => true],
    );

    return Company::query()->create([
        'name' => 'Currency Unit Co',
        'slug' => 'currency-unit-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}
