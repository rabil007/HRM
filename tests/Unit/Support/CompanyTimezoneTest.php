<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Services\Settings\SettingService;
use App\Support\Settings\CompanyTimezone;
use App\Support\Settings\SettingKey;

test('company timezone uses company identifier when valid', function () {
    $company = makeTimezoneCompany('Asia/Dubai');

    expect(CompanyTimezone::forCompany($company))->toBe('Asia/Dubai');
    expect(CompanyTimezone::forCompanyId((int) $company->id))->toBe('Asia/Dubai');
});

test('company timezone falls back to application timezone for invalid values', function () {
    app(SettingService::class)->set(SettingKey::Timezone, 'Europe/London');

    $company = makeTimezoneCompany('Invalid/Zone');

    expect(CompanyTimezone::forCompany($company))->toBe('Europe/London');
});

test('company timezone falls back when company missing', function () {
    app(SettingService::class)->set(SettingKey::Timezone, 'UTC');

    expect(CompanyTimezone::forCompany(null))->toBe('UTC');
});

function makeTimezoneCompany(string $timezone): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'TZU'],
        ['name' => 'Timezone Unit', 'dial_code' => '+0', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => 'TZ Unit Co',
        'slug' => 'tz-unit-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => $timezone,
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}
