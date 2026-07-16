<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignmentSequence;
use App\Models\Currency;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use Illuminate\Support\Str;

test('next generates sequential assignment numbers for same company and year', function () {
    $country = Country::query()->create([
        'code' => 'CANG',
        'name' => 'Assignment Number Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CANG',
        'name' => 'Assignment Number Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Assignment Number Co',
        'slug' => 'assignment-number-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $generator = new CrewAssignmentNumberGenerator;

    $first = $generator->next($company->id, 2027);
    $second = $generator->next($company->id, 2027);
    $third = $generator->next($company->id, 2027);

    expect($first)->toBe('CA-2027-000001')
        ->and($second)->toBe('CA-2027-000002')
        ->and($third)->toBe('CA-2027-000003');

    $sequence = CrewAssignmentSequence::query()
        ->where('company_id', $company->id)
        ->where('year', 2027)
        ->first();

    expect($sequence)->not->toBeNull()
        ->and($sequence->last_number)->toBe(3);
});

test('next resets sequence for different years', function () {
    $country = Country::query()->create([
        'code' => 'CANY',
        'name' => 'Year Reset Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CANY',
        'name' => 'Year Reset Currency',
        'symbol' => 'Y$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Year Reset Co',
        'slug' => 'year-reset-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $generator = new CrewAssignmentNumberGenerator;

    $year2027 = $generator->next($company->id, 2027);
    $year2028 = $generator->next($company->id, 2028);
    $year2027Again = $generator->next($company->id, 2027);

    expect($year2027)->toBe('CA-2027-000001')
        ->and($year2028)->toBe('CA-2028-000001')
        ->and($year2027Again)->toBe('CA-2027-000002');
});

test('next is isolated per company', function () {
    $country = Country::query()->create([
        'code' => 'CANC',
        'name' => 'Company Isolation Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CANC',
        'name' => 'Company Isolation Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Company A',
        'slug' => 'company-a-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Company B',
        'slug' => 'company-b-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $generator = new CrewAssignmentNumberGenerator;

    $numberA1 = $generator->next($companyA->id, 2027);
    $numberB1 = $generator->next($companyB->id, 2027);
    $numberA2 = $generator->next($companyA->id, 2027);
    $numberB2 = $generator->next($companyB->id, 2027);

    expect($numberA1)->toBe('CA-2027-000001')
        ->and($numberB1)->toBe('CA-2027-000001')
        ->and($numberA2)->toBe('CA-2027-000002')
        ->and($numberB2)->toBe('CA-2027-000002');
});

test('next pads sequence number with leading zeros', function () {
    $country = Country::query()->create([
        'code' => 'CANP',
        'name' => 'Padding Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CANP',
        'name' => 'Padding Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Padding Co',
        'slug' => 'padding-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    CrewAssignmentSequence::query()->create([
        'company_id' => $company->id,
        'year' => 2027,
        'last_number' => 99,
    ]);

    $generator = new CrewAssignmentNumberGenerator;

    $next = $generator->next($company->id, 2027);

    expect($next)->toBe('CA-2027-000100');
});

test('next uses current year when year is not specified', function () {
    $country = Country::query()->create([
        'code' => 'CAND',
        'name' => 'Default Year Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CAND',
        'name' => 'Default Year Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Default Year Co',
        'slug' => 'default-year-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $generator = new CrewAssignmentNumberGenerator;

    $number = $generator->next($company->id);
    $currentYear = now($company->timezone)->year;

    expect($number)->toBe("CA-{$currentYear}-000001");
});
