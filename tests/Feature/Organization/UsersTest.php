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
use Spatie\Permission\PermissionRegistrar;

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

test('user update ignores password field and does not mutate the stored hash', function () {
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

    $originalHash = bcrypt('original-password');

    $targetUser = User::query()->create([
        'company_id' => $company->id,
        'name' => 'Password User',
        'email' => 'password-user@example.com',
        'password' => $originalHash,
        'status' => 'active',
    ]);

    grantCompanyPermissions($auth, $company, ['users.update']);

    // Submitting a password field via normal edit should be silently ignored
    $this->put("/organization/users/{$targetUser->id}", [
        'name' => 'Password User',
        'email' => 'password-user@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
        'role_id' => '',
        'status' => 'active',
    ])->assertRedirect('/organization/users');

    // The stored password hash must not have changed
    $refreshed = $targetUser->fresh();
    expect($refreshed->password)->toBe($originalHash);
});

test('user directory index can be filtered by role', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    $country = Country::query()->create([
        'code' => 'URF',
        'name' => 'User Role Filter Land',
        'dial_code' => '+973',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'URF',
        'name' => 'User Role Filter Currency',
        'symbol' => 'U$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'User Role Filter Co',
        'slug' => 'user-role-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Tester',
        'guard_name' => 'web',
    ]);

    $userWithRole = User::factory()->create(['company_id' => $company->id]);
    $userWithRole->assignRole($role);

    // Create another user without the role
    $otherUser = User::factory()->create(['company_id' => $company->id]);

    grantCompanyPermissions($auth, $company, ['users.view']);

    $response = $this->get('/organization/users?'.http_build_query([
        'role_id' => $role->id,
    ]))->assertOk();

    $ids = collect($response->viewData('page')['props']['users'])->pluck('id')->all();

    expect($ids)->toContain($userWithRole->id);
    expect($ids)->not->toContain($otherUser->id);
});

test('users directory lists both home-company users and multi-company members', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.view']);

    // User 1: home company is Company A
    $homeUser = User::factory()->create([
        'company_id' => $companyA->id,
        'status' => 'active',
    ]);

    // User 2: home company is Company B, but has membership in Company A
    $memberUser = User::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'active',
    ]);
    $memberUser->companies()->attach($companyA->id, ['status' => 'active']);

    // User 3: belongs only to Company B
    $otherCompanyUser = User::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('organization.users'))
        ->assertOk();

    $ids = collect($response->viewData('page')['props']['users'])->pluck('id')->all();

    expect($ids)->toContain($homeUser->id)
        ->and($ids)->toContain($memberUser->id)
        ->and($ids)->not->toContain($otherCompanyUser->id);
});

test('users directory can be filtered by presence and exposes two_factor_enabled as boolean', function () {
    config(['session.driver' => 'database']);

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.view']);

    // Online user (active session 2 minutes ago) with confirmed 2FA
    $onlineUser = User::factory()->withTwoFactor()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    DB::table('sessions')->insert([
        'id' => 'online-session',
        'user_id' => $onlineUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time() - 120, // 2 mins ago
    ]);

    // Never-active user (no login, no session) without 2FA
    $neverUser = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
        'last_login_at' => null,
    ]);

    // Filter by online presence
    $responseOnline = $this->actingAs($admin)
        ->get(route('organization.users', ['presence' => 'online']))
        ->assertOk();

    $usersOnline = collect($responseOnline->viewData('page')['props']['users']);
    $idsOnline = $usersOnline->pluck('id')->all();

    expect($idsOnline)->toContain($onlineUser->id)
        ->and($idsOnline)->not->toContain($neverUser->id);

    $onlinePayload = $usersOnline->firstWhere('id', $onlineUser->id);
    expect($onlinePayload['two_factor_enabled'])->toBeTrue()
        ->and($onlinePayload)->not->toHaveKey('two_factor_secret')
        ->and($onlinePayload)->not->toHaveKey('two_factor_recovery_codes');

    // Filter by never presence
    $responseNever = $this->actingAs($admin)
        ->get(route('organization.users', ['presence' => 'never']))
        ->assertOk();

    $usersNever = collect($responseNever->viewData('page')['props']['users']);
    $idsNever = $usersNever->pluck('id')->all();

    expect($idsNever)->toContain($neverUser->id)
        ->and($idsNever)->not->toContain($onlineUser->id);

    $neverPayload = $usersNever->firstWhere('id', $neverUser->id);
    expect($neverPayload['two_factor_enabled'])->toBeFalse();
});

test('membership-only user is accessible via show() when active membership exists', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.view']);

    // User whose home company is B but has an active membership in A
    $memberUser = User::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $memberUser->companies()->attach($companyA->id, ['status' => 'active']);

    $this->actingAs($admin)
        ->get("/organization/users/{$memberUser->id}")
        ->assertOk();
});

test('membership-only user show() returns 404 when membership is inactive', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.view']);

    $memberUser = User::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $memberUser->companies()->attach($companyA->id, ['status' => 'inactive']);

    $this->actingAs($admin)
        ->get("/organization/users/{$memberUser->id}")
        ->assertNotFound();
});

test('inactive membership excludes user from users directory', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.view']);

    // User with INACTIVE membership in Company A
    $inactiveMember = User::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $inactiveMember->companies()->attach($companyA->id, ['status' => 'inactive']);

    // User with ACTIVE membership in Company A
    $activeMember = User::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $activeMember->companies()->attach($companyA->id, ['status' => 'active']);

    $response = $this->actingAs($admin)
        ->get(route('organization.users'))
        ->assertOk();

    $ids = collect($response->viewData('page')['props']['users'])->pluck('id')->all();

    expect($ids)->toContain($activeMember->id)
        ->and($ids)->not->toContain($inactiveMember->id);
});

test('per-row capabilities are correctly set for home-company and membership-only users', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.view']);

    // Home company user (company_id = A)
    $homeUser = User::factory()->create(['company_id' => $companyA->id, 'status' => 'active']);

    // Membership-only user (home company = B, membership in A)
    $memberUser = User::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $memberUser->companies()->attach($companyA->id, ['status' => 'active']);

    $response = $this->actingAs($admin)
        ->get(route('organization.users'))
        ->assertOk();

    $usersPayload = collect($response->viewData('page')['props']['users']);

    $homePayload = $usersPayload->firstWhere('id', $homeUser->id);
    $memberPayload = $usersPayload->firstWhere('id', $memberUser->id);

    // Home-company user gets global identity capabilities
    expect($homePayload['capabilities']['can_edit_global_identity'])->toBeTrue()
        ->and($homePayload['capabilities']['can_delete_global_identity'])->toBeTrue()
        ->and($homePayload['capabilities']['can_manage_membership'])->toBeTrue();

    // Membership-only user must NOT have global identity capabilities
    expect($memberPayload['capabilities']['can_edit_global_identity'])->toBeFalse()
        ->and($memberPayload['capabilities']['can_delete_global_identity'])->toBeFalse()
        ->and($memberPayload['capabilities']['can_manage_membership'])->toBeTrue();
});
