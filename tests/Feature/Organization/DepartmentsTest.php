<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\User;

test('guests cannot access departments page', function () {
    $this->get('/organization/departments')->assertRedirect(route('login'));
});

test('authenticated users can view departments page', function () {
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

    grantCompanyPermissions($user, $company, ['departments.view']);

    $this->get('/organization/departments')->assertOk();
});

test('authenticated users can view a department details page', function () {
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

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'HR',
        'code' => 'HR',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.view']);

    $this->get("/organization/departments/{$department->id}")->assertOk();
});

test('authenticated users can create, update, and delete a department', function () {
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

    $manager = User::factory()->create(['name' => 'Manager']);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.create', 'departments.update', 'departments.delete', 'departments.view']);

    $this->post('/organization/departments', [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'parent_id' => $parent->id,
        'manager_id' => $manager->id,
        'name' => 'HR',
        'code' => 'HR',
        'status' => 'active',
    ])->assertRedirect('/organization/departments');

    $departmentId = Department::query()->where('company_id', $company->id)->where('code', 'HR')->value('id');
    expect($departmentId)->not->toBeNull();

    $this->put("/organization/departments/{$departmentId}", [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'parent_id' => $parent->id,
        'manager_id' => $manager->id,
        'name' => 'HR Updated',
        'code' => 'HR',
        'status' => 'inactive',
    ])->assertRedirect('/organization/departments');

    $this->assertDatabaseHas('departments', [
        'id' => $departmentId,
        'name' => 'HR Updated',
        'status' => 'inactive',
    ]);

    $this->delete("/organization/departments/{$departmentId}")->assertRedirect('/organization/departments');
    $this->assertDatabaseMissing('departments', ['id' => $departmentId]);
});

test('authenticated users can export departments as csv, excel, and pdf', function () {
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

    Department::query()->create([
        'company_id' => $company->id,
        'name' => 'HR Export',
        'code' => 'HRX',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.view', 'departments.export']);

    $csv = $this->get('/organization/departments/export?format=csv&search=HRX');
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get('/organization/departments/export?format=xlsx&search=HRX');
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdf = $this->get('/organization/departments/export?format=pdf&search=HRX');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});
