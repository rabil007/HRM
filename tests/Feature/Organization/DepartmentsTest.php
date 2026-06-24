<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

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

    $manager = Employee::factory()->forCompany($company)->create([
        'name' => 'Manager',
        'employee_no' => 'MGR001',
    ]);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.create', 'departments.update', 'departments.delete', 'departments.view']);

    $this->post('/organization/departments', [
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
        'branch_id' => $branch->id,
        'parent_id' => $parent->id,
        'manager_id' => $manager->id,
        'name' => 'HR Updated',
        'code' => 'HR',
        'status' => 'inactive',
    ])->assertRedirect('/organization/departments');

    $this->assertDatabaseHas('departments', [
        'id' => $departmentId,
        'manager_id' => null,
        'name' => 'HR Updated',
        'status' => 'inactive',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Department::class)
        ->where('subject_id', $departmentId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();

    $this->delete("/organization/departments/{$departmentId}")->assertRedirect('/organization/departments');
    $this->assertSoftDeleted('departments', ['id' => $departmentId]);
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

test('parent departments can have a manager assigned', function () {
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

    $manager = Employee::factory()->forCompany($company)->create([
        'name' => 'Parent Manager',
        'employee_no' => 'PM100',
    ]);

    grantCompanyPermissions($user, $company, ['departments.create', 'departments.view']);

    $this->post('/organization/departments', [
        'manager_id' => $manager->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ])->assertRedirect('/organization/departments');

    $this->assertDatabaseHas('departments', [
        'company_id' => $company->id,
        'code' => 'OPS',
        'parent_id' => null,
        'manager_id' => $manager->id,
    ]);
});

test('child departments cannot keep a manager assignment', function () {
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

    $manager = Employee::factory()->forCompany($company)->create([
        'name' => 'Blocked Manager',
        'employee_no' => 'BM100',
    ]);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['departments.create', 'departments.update']);

    $this->post('/organization/departments', [
        'parent_id' => $parent->id,
        'manager_id' => $manager->id,
        'name' => 'HR',
        'code' => 'HR',
        'status' => 'active',
    ])->assertRedirect('/organization/departments');

    $childId = Department::query()->where('company_id', $company->id)->where('code', 'HR')->value('id');

    $this->assertDatabaseHas('departments', [
        'id' => $childId,
        'parent_id' => $parent->id,
        'manager_id' => null,
    ]);

    $parentManager = Employee::factory()->forCompany($company)->create([
        'name' => 'Parent Manager',
        'employee_no' => 'PM200',
    ]);

    $parentWithManager = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Finance',
        'code' => 'FIN',
        'manager_id' => $parentManager->id,
        'status' => 'active',
    ]);

    $this->put("/organization/departments/{$parentWithManager->id}", [
        'parent_id' => $parent->id,
        'manager_id' => $parentManager->id,
        'name' => 'Finance',
        'code' => 'FIN',
        'status' => 'active',
    ])->assertRedirect('/organization/departments');

    $this->assertDatabaseHas('departments', [
        'id' => $parentWithManager->id,
        'parent_id' => $parent->id,
        'manager_id' => null,
    ]);
});

test('departments page lists employees as manager options', function () {
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

    $manager = Employee::factory()->forCompany($company)->create([
        'name' => 'Department Manager',
        'employee_no' => 'DM100',
    ]);

    User::factory()->create([
        'company_id' => $company->id,
        'name' => 'Login Only User',
    ]);

    grantCompanyPermissions($user, $company, ['departments.view']);

    $this->get('/organization/departments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/departments')
            ->has('managers', 1)
            ->where('managers.0.id', $manager->id)
            ->where('managers.0.name', 'Department Manager')
            ->where('managers.0.employee_no', 'DM100')
        );
});

test('authenticated users can toggle department status', function () {
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

    grantCompanyPermissions($user, $company, ['departments.update']);

    $this->put("/organization/departments/{$department->id}/status", [
        'status' => 'inactive',
    ])->assertRedirect('/organization/departments');

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'status' => 'inactive',
    ]);
});
