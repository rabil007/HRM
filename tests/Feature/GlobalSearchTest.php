<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;

function makeGlobalSearchCompany(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => 'GS'.$suffix,
        'name' => 'Searchland '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'G'.$suffix,
        'name' => 'Search Currency '.$suffix,
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Search Co '.$suffix,
        'slug' => 'search-co-'.strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('global search requires authentication', function () {
    $this->getJson('/search?q=alpha')->assertUnauthorized();
});

test('global search returns permitted records from the active company only', function () {
    $user = User::factory()->create();
    $company = makeGlobalSearchCompany('A1');
    $otherCompany = makeGlobalSearchCompany('B2');

    grantCompanyPermissions($user, $company, ['employees.view']);

    Employee::factory()->forCompany($company)->create([
        'name' => 'Alpha Current Company',
        'employee_no' => 'ALPHA-001',
    ]);
    Employee::factory()->forCompany($otherCompany)->create([
        'name' => 'Alpha Other Company',
        'employee_no' => 'ALPHA-999',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson('/search?q=Alpha')
        ->assertOk()
        ->assertJsonPath('results.0.title', 'Alpha Current Company')
        ->assertJsonMissing(['title' => 'Alpha Other Company']);
});

test('global search does not expose record families without their view permission', function () {
    $user = User::factory()->create();
    $company = makeGlobalSearchCompany('C3');

    grantCompanyPermissions($user, $company, ['branches.view']);

    Employee::factory()->forCompany($company)->create([
        'name' => 'Hidden Employee',
        'employee_no' => 'HIDDEN-001',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson('/search?q=Hidden')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});
