<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

test('guests cannot access users page', function () {
    $this->get('/organization/users')->assertRedirect(route('login'));
});

test('authenticated users can view users page', function () {
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

    grantCompanyPermissions($user, $company, ['users.view']);

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

    $user = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    grantCompanyPermissions($auth, $company, ['users.view']);

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

    grantCompanyPermissions($auth, $company, ['users.create', 'users.update', 'users.delete', 'users.view']);

    $role = Role::query()->firstOrCreate([
        'company_id' => $company->id,
        'name' => 'HR Manager',
        'guard_name' => 'web',
    ]);

    $this->post('/organization/users', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $role->id,
        'status' => 'active',
    ])->assertRedirect('/organization/users');

    $userId = User::query()->where('email', 'john@example.com')->value('id');
    expect($userId)->not->toBeNull();
    $this->assertDatabaseHas('users', ['id' => $userId, 'company_id' => $company->id]);
    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $userId,
    ]);

    $this->put("/organization/users/{$userId}", [
        'name' => 'John Updated',
        'email' => 'john@example.com',
        'password' => '',
        'role_id' => '',
        'status' => 'inactive',
    ])->assertRedirect('/organization/users');

    $this->assertDatabaseHas('users', [
        'id' => $userId,
        'name' => 'John Updated',
        'status' => 'inactive',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', User::class)
        ->where('subject_id', $userId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();

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

    grantCompanyPermissions($auth, $company, ['users.view', 'users.export']);

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

test('authenticated users can toggle user status', function () {
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

    $user = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Jane Doe',
        'email' => 'jane-toggle@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    grantCompanyPermissions($auth, $company, ['users.update']);

    $this->put("/organization/users/{$user->id}/status", [
        'status' => 'inactive',
    ])->assertRedirect('/organization/users');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'status' => 'inactive',
    ]);
});

test('user update can copy avatar from linked employee photo', function () {
    Storage::fake('public');

    $auth = User::factory()->create();
    $this->actingAs($auth);

    $country = Country::query()->create([
        'code' => 'AVT',
        'name' => 'Avatarland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'AVT',
        'name' => 'Avatar Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Avatar Co',
        'slug' => 'avatar-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $targetUser = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Linked User',
        'email' => 'linked-user@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
        'avatar' => null,
    ]);

    $employeeImagePath = UploadedFile::fake()
        ->image('employee.jpg', 200, 200)
        ->store("employees/{$company->id}/images", 'public');

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'user_id' => $targetUser->id,
            'employee_no' => 'EMP-LINK',
            'name' => 'Linked Employee',
            'image' => $employeeImagePath,
        ]);

    grantCompanyPermissions($auth, $company, ['users.update']);

    $this->put("/organization/users/{$targetUser->id}", [
        'name' => 'Linked User',
        'email' => 'linked-user@example.com',
        'password' => '',
        'role_id' => '',
        'status' => 'active',
        'employee_id' => $employee->id,
        'use_employee_avatar' => true,
    ])->assertRedirect('/organization/users');

    $targetUser->refresh();

    expect($targetUser->avatar)->not->toBeNull()
        ->and($targetUser->avatar)->not->toBe($employeeImagePath)
        ->and(Storage::disk('public')->exists($targetUser->avatar))->toBeTrue()
        ->and(Storage::disk('public')->exists($employeeImagePath))->toBeTrue();
});

test('user update can link and unlink an employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    $country = Country::query()->create([
        'code' => 'LNK',
        'name' => 'Linkland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'LNK',
        'name' => 'Link Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Link Co',
        'slug' => 'link-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $targetUser = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Link User',
        'email' => 'link-user@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'user_id' => null,
            'employee_no' => 'EMP-LINK-1',
            'name' => 'Linkable Employee',
        ]);

    grantCompanyPermissions($auth, $company, ['users.update']);

    $this->put("/organization/users/{$targetUser->id}", [
        'name' => 'Link User',
        'email' => 'link-user@example.com',
        'password' => '',
        'role_id' => '',
        'status' => 'active',
        'employee_id' => $employee->id,
    ])->assertRedirect('/organization/users');

    expect($employee->fresh()->user_id)->toBe($targetUser->id);

    $this->put("/organization/users/{$targetUser->id}", [
        'name' => 'Link User',
        'email' => 'link-user@example.com',
        'password' => '',
        'role_id' => '',
        'status' => 'active',
        'employee_id' => '',
    ])->assertRedirect('/organization/users');

    expect($employee->fresh()->user_id)->toBeNull();
});

test('user update requires matching password confirmation when changing password', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    $country = Country::query()->create([
        'code' => 'PWD',
        'name' => 'Passwordland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PWD',
        'name' => 'Password Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Password Co',
        'slug' => 'password-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $targetUser = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Password User',
        'email' => 'password-user@example.com',
        'password' => bcrypt('password123'),
        'status' => 'active',
    ]);

    grantCompanyPermissions($auth, $company, ['users.update']);

    $this->from('/organization/users')
        ->put("/organization/users/{$targetUser->id}", [
            'name' => 'Password User',
            'email' => 'password-user@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
            'role_id' => '',
            'status' => 'active',
        ])
        ->assertRedirect('/organization/users')
        ->assertSessionHasErrors('password');
});
