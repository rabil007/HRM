<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('guests cannot access roles page', function () {
    $this->get('/organization/roles')->assertRedirect(route('login'));
});

test('authenticated users can view roles page', function () {
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

    grantCompanyPermissions($user, $company, ['roles.view']);

    $this->get('/organization/roles')->assertOk();
});

test('authenticated users can view a role details page', function () {
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

    Permission::findOrCreate('companies.view', 'web');
    Permission::findOrCreate('users.view', 'web');

    Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Admin',
        'guard_name' => 'web',
    ])->syncPermissions(['companies.view', 'users.view']);

    grantCompanyPermissions($user, $company, ['roles.view']);

    $roleId = Role::query()->where('company_id', $company->id)->where('name', 'Admin')->value('id');
    expect($roleId)->not->toBeNull();
    $this->get("/organization/roles/{$roleId}")->assertOk();
});

test('authenticated users can create, update, and delete a role', function () {
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

    grantCompanyPermissions($user, $company, ['roles.create', 'roles.update', 'roles.delete', 'roles.view']);

    $this->post('/organization/roles', [
        'name' => 'HR Admin',
        'permissions' => ['departments.view', 'departments.update'],
    ])->assertRedirect('/organization/roles');

    $roleId = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'HR Admin')
        ->value('id');

    expect($roleId)->not->toBeNull();

    $this->put("/organization/roles/{$roleId}", [
        'name' => 'HR Admin Updated',
        'permissions' => ['departments.view'],
    ])->assertRedirect('/organization/roles');

    $this->assertDatabaseHas('spatie_roles', [
        'id' => $roleId,
        'name' => 'HR Admin Updated',
    ]);

    $this->delete("/organization/roles/{$roleId}")->assertRedirect('/organization/roles');
    $this->assertDatabaseMissing('spatie_roles', ['id' => $roleId]);
});

test('authenticated users can delete a role', function () {
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

    $roleId = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Temp',
        'guard_name' => 'web',
    ])->id;

    grantCompanyPermissions($user, $company, ['roles.delete', 'roles.view']);

    $this->delete("/organization/roles/{$roleId}")->assertRedirect('/organization/roles');
    $this->assertDatabaseMissing('spatie_roles', ['id' => $roleId]);
});

test('authenticated users can export roles as csv, excel, and pdf', function () {
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

    Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Export Role',
        'slug' => 'export-role',
        'permissions' => ['companies.view'],
        'is_system' => false,
    ]);

    grantCompanyPermissions($user, $company, ['roles.view', 'roles.export']);

    $csv = $this->get('/organization/roles/export?format=csv&search=export-role');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/roles/export?format=xlsx&search=export-role');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/roles/export?format=pdf&search=export-role');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});
