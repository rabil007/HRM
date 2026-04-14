<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

test('guests cannot access branches page', function () {
    $this->get('/organization/branches')->assertRedirect(route('login'));
});

test('authenticated users can view branches page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['branches.view']);

    $this->get('/organization/branches')->assertOk();
});

test('authenticated users can export branches as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['branches.view', 'branches.export']);

    Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ Export',
        'code' => 'HQX',
        'city' => 'Dubai',
        'country' => 'UAE',
        'status' => 'active',
        'is_headquarters' => true,
        'email' => 'hq@acme.test',
        'phone' => '+971',
    ]);

    $csv = $this->get('/organization/branches/export?format=csv&search=HQX');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/branches/export?format=xlsx&search=HQX');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/branches/export?format=pdf&search=HQX');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('authenticated users can view a branch details page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'city' => 'Dubai',
        'country' => 'UAE',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    grantCompanyPermissions($user, $company, ['branches.view']);

    $this->get("/organization/branches/{$branch->id}")->assertOk();
});

test('authenticated users can create, update, and delete a branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['branches.create', 'branches.update', 'branches.delete', 'branches.view']);

    $this->post('/organization/branches', [
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => '+971',
        'email' => 'hq@acme.test',
        'is_headquarters' => true,
        'status' => 'active',
    ])->assertRedirect('/organization/branches');

    $branchId = Branch::query()->where('company_id', $company->id)->where('code', 'HQ')->value('id');
    expect($branchId)->not->toBeNull();

    $this->put("/organization/branches/{$branchId}", [
        'company_id' => $company->id,
        'name' => 'HQ Updated',
        'code' => 'HQ',
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => '+971',
        'email' => 'hq@acme.test',
        'is_headquarters' => false,
        'status' => 'inactive',
    ])->assertRedirect('/organization/branches');

    $this->assertDatabaseHas('branches', [
        'id' => $branchId,
        'name' => 'HQ Updated',
        'status' => 'inactive',
    ]);

    $this->delete("/organization/branches/{$branchId}")->assertRedirect('/organization/branches');
    $this->assertDatabaseMissing('branches', ['id' => $branchId]);
});
