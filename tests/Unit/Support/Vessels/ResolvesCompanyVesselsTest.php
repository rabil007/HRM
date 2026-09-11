<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Vessels\ResolvesCompanyVessels;

test('active vessel options include client_id', function () {
    $country = Country::query()->create([
        'code' => 'RCV',
        'name' => 'Resolves Vessel Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RCV',
        'name' => 'Resolves Vessel Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Resolves Vessel Co',
        'slug' => 'resolves-vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Resolves Vessel Co',
        'slug' => 'other-resolves-vessel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC '.uniqid(),
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS '.uniqid(),
        'is_active' => true,
    ]);

    $assigned = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Assigned Vessel '.uniqid(),
        'is_active' => true,
    ]);

    $unassigned = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => null,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Unassigned Vessel '.uniqid(),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Other Company Vessel '.uniqid(),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Inactive Vessel '.uniqid(),
        'is_active' => false,
    ]);

    $options = ResolvesCompanyVessels::activeOptions((int) $company->id);

    expect($options)->toHaveCount(2)
        ->and(collect($options)->firstWhere('id', $assigned->id))->toMatchArray([
            'id' => $assigned->id,
            'name' => $assigned->name,
            'client_id' => $client->id,
        ])
        ->and(collect($options)->firstWhere('id', $unassigned->id))->toMatchArray([
            'id' => $unassigned->id,
            'name' => $unassigned->name,
            'client_id' => null,
        ]);
});
