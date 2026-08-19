<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
        'wps_employer_iban' => 'AE070331234567890123456',
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
        'wps_employer_iban' => 'AE070331234567890123456',
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

test('updating a company without a logo preserves the existing logo', function () {
    Storage::fake('public');

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

    $logoPath = 'company-logos/existing-logo.png';
    Storage::disk('public')->put($logoPath, 'fake image content');

    $company = Company::query()->create([
        'name' => 'Acme With Logo',
        'slug' => 'acme-with-logo',
        'logo' => $logoPath,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.update']);

    $this->put("/organization/companies/{$company->id}", [
        'name' => 'Acme Updated Name',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
    ])->assertRedirect('/organization/companies');

    $company->refresh();
    expect($company->name)->toBe('Acme Updated Name');
    expect($company->logo)->toBe($logoPath);
    expect(Storage::disk('public')->exists($logoPath))->toBeTrue();
});

test('updating a company with remove_logo removes and deletes the logo', function () {
    Storage::fake('public');

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

    $logoPath = 'company-logos/logo-to-delete.png';
    Storage::disk('public')->put($logoPath, 'fake image content');

    $company = Company::query()->create([
        'name' => 'Acme With Logo To Delete',
        'slug' => 'acme-with-logo-to-delete',
        'logo' => $logoPath,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.update']);

    $this->put("/organization/companies/{$company->id}", [
        'name' => 'Acme Without Logo',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'remove_logo' => true,
    ])->assertRedirect('/organization/companies');

    $company->refresh();
    expect($company->logo)->toBeNull();
    expect(Storage::disk('public')->exists($logoPath))->toBeFalse();
});

test('updating a company with a new logo replaces and deletes the old logo', function () {
    Storage::fake('public');

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

    $oldLogoPath = 'company-logos/old-logo.png';
    Storage::disk('public')->put($oldLogoPath, 'old fake image content');

    $company = Company::query()->create([
        'name' => 'Acme To Replace Logo',
        'slug' => 'acme-to-replace-logo',
        'logo' => $oldLogoPath,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.update']);

    $newFile = UploadedFile::fake()->image('new-logo.png');

    $this->put("/organization/companies/{$company->id}", [
        'name' => 'Acme With New Logo',
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'logo' => $newFile,
    ])->assertRedirect('/organization/companies');

    $company->refresh();
    expect($company->logo)->not->toBeNull();
    expect($company->logo)->not->toBe($oldLogoPath);
    expect(Storage::disk('public')->exists($oldLogoPath))->toBeFalse();
    expect(Storage::disk('public')->exists($company->logo))->toBeTrue();
});
