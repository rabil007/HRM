<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

test('guests cannot access companies pages', function () {
    $this->get('/organization/companies')->assertRedirect(route('login'));
    $this->get('/organization/companies/1')->assertRedirect(route('login'));
});

test('authenticated users can export companies as csv, excel, and pdf', function () {
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
        'name' => 'Acme Export',
        'slug' => 'acme-export',
        'industry' => 'Tech',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.view', 'companies.export']);

    $csv = $this->get('/organization/companies/export?format=csv&search=Acme');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/companies/export?format=xlsx&search=Acme');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/companies/export?format=pdf&search=Acme');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

test('authenticated users can view company details page', function () {
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

    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->get("/organization/companies/{$company->id}")->assertOk();
});

test('authenticated users can update a company with all fields', function () {
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

    grantCompanyPermissions($user, $company, ['companies.update']);

    $this->put("/organization/companies/{$company->id}", [
        'name' => 'Acme Updated',
        'industry' => 'Technology',
        'company_size' => '1-50',
        'registration_number' => 'REG-123',
        'tax_id' => 'TAX-123',
        'country_id' => $country->id,
        'city' => 'Dubai',
        'address' => 'Test address',
        'phone' => '+971555000000',
        'email' => 'hr@acme.test',
        'website' => 'acme.test',
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'weekly',
        'working_days' => [1, 2, 3, 4],
        'wps_agent_code' => 'AGENT-1',
        'wps_mol_uid' => 'MOL-1',
        'status' => 'suspended',
    ])->assertRedirect('/organization/companies');

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'name' => 'Acme Updated',
        'industry' => 'Technology',
        'company_size' => '1-50',
        'registration_number' => 'REG-123',
        'tax_id' => 'TAX-123',
        'city' => 'Dubai',
        'address' => 'Test address',
        'phone' => '+971555000000',
        'email' => 'hr@acme.test',
        'website' => 'acme.test',
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'weekly',
        'wps_agent_code' => 'AGENT-1',
        'wps_mol_uid' => 'MOL-1',
        'status' => 'suspended',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Company::class)
        ->where('subject_id', $company->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
});

test('authenticated users can toggle company status', function () {
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

    grantCompanyPermissions($user, $company, ['companies.update']);

    $this->put("/organization/companies/{$company->id}/status", [
        'status' => 'inactive',
    ])->assertRedirect('/organization/companies');

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'status' => 'inactive',
    ]);
});

test('creating a company assigns creator as owner with all permissions', function () {
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

    $existingCompany = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $existingCompany, ['companies.create']);

    $this->post('/organization/companies', [
        'name' => 'NewCo',
        'slug' => 'newco',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'working_days' => [1, 2, 3, 4, 5],
        'status' => 'active',
    ])->assertRedirect('/organization/companies');

    $companyId = Company::query()->where('slug', 'newco')->value('id');
    expect($companyId)->not->toBeNull();

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyId,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $ownerRoleId = Role::query()
        ->where('company_id', $companyId)
        ->where('name', 'Owner')
        ->value('id');

    expect($ownerRoleId)->not->toBeNull();

    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyId,
        'role_id' => $ownerRoleId,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
});
