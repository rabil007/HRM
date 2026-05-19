<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('inertia json responses are not stored in browser or cdn cache', function () {
    $manifestPath = public_path('build/manifest.json');

    if (! file_exists($manifestPath)) {
        $this->markTestSkipped('Vite manifest is not built.');
    }

    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'ICH',
        'name' => 'Cacheland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ICH',
        'name' => 'Cache Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Cache Co',
        'slug' => 'cache-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view']);

    $version = hash_file('xxh128', $manifestPath);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ])
        ->get('/organization/positions');

    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('max-age=0');
});

test('authenticated html document responses are not cacheable', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'IHT',
        'name' => 'Html Cacheland',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'IHT',
        'name' => 'Html Cache Currency',
        'symbol' => 'H$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Html Cache Co',
        'slug' => 'html-cache-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['positions.view']);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/positions');

    $response->assertOk();

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('no-cache')
        ->and($cacheControl)->toContain('must-revalidate')
        ->and($cacheControl)->toContain('private');
});
