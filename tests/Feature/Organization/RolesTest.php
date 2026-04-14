<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;

test('guests cannot access roles page', function () {
    $this->get('/organization/roles')->assertRedirect(route('login'));
});

test('authenticated users can view roles page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Admin',
        'slug' => 'admin',
        'permissions' => ['companies.view', 'users.view'],
        'is_system' => false,
    ]);

    $this->get("/organization/roles/{$role->id}")->assertOk();
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

    $this->post('/organization/roles', [
        'company_id' => $company->id,
        'name' => 'HR Admin',
        'slug' => 'hr-admin',
        'permissions' => ['departments.view', 'departments.update'],
        'is_system' => false,
    ])->assertRedirect('/organization/roles');

    $roleId = Role::query()
        ->where('company_id', $company->id)
        ->where('slug', 'hr-admin')
        ->value('id');

    expect($roleId)->not->toBeNull();

    $this->put("/organization/roles/{$roleId}", [
        'company_id' => $company->id,
        'name' => 'HR Admin Updated',
        'slug' => 'hr-admin',
        'permissions' => ['departments.view'],
        'is_system' => false,
    ])->assertRedirect('/organization/roles');

    $this->assertDatabaseHas('roles', [
        'id' => $roleId,
        'name' => 'HR Admin Updated',
        'slug' => 'hr-admin',
    ]);

    $this->delete("/organization/roles/{$roleId}")->assertRedirect('/organization/roles');
    $this->assertDatabaseMissing('roles', ['id' => $roleId]);
});

test('system roles cannot be deleted', function () {
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

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'System Admin',
        'slug' => 'system-admin',
        'permissions' => ['*'],
        'is_system' => true,
    ]);

    $this->delete("/organization/roles/{$role->id}")->assertRedirect('/organization/roles');
    $this->assertDatabaseHas('roles', ['id' => $role->id]);
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
