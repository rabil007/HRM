<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;

test('guests cannot access users page', function () {
    $this->get('/organization/users')->assertRedirect(route('login'));
});

test('authenticated users can view users page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/organization/users')->assertOk();
});

test('authenticated users can view a user details page', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

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
        'name' => 'Viewer',
        'slug' => 'viewer',
        'permissions' => ['companies.view'],
        'is_system' => false,
    ]);

    $user = User::query()->create([
        'company_id' => $company->id,
        'role_id' => $role->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    $this->get("/organization/users/{$user->id}")->assertOk();
});

test('authenticated users can create, update, and delete a user', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

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
        'name' => 'HR Admin',
        'slug' => 'hr-admin',
        'permissions' => ['departments.view'],
        'is_system' => false,
    ]);

    $this->post('/organization/users', [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'status' => 'active',
    ])->assertRedirect('/organization/users');

    $userId = User::query()->where('email', 'john@example.com')->value('id');
    expect($userId)->not->toBeNull();

    $this->put("/organization/users/{$userId}", [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'name' => 'John Updated',
        'email' => 'john@example.com',
        'password' => '',
        'status' => 'inactive',
    ])->assertRedirect('/organization/users');

    $this->assertDatabaseHas('users', [
        'id' => $userId,
        'name' => 'John Updated',
        'status' => 'inactive',
    ]);

    $this->delete("/organization/users/{$userId}")->assertRedirect('/organization/users');
});

test('authenticated users can export users as csv, excel, and pdf', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

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

    User::query()->create([
        'company_id' => $company->id,
        'name' => 'Export User',
        'email' => 'export-user@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    $csv = $this->get('/organization/users/export?format=csv&search=export-user');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/users/export?format=xlsx&search=export-user');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/users/export?format=pdf&search=export-user');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});
