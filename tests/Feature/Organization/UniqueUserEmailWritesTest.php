<?php

use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use Spatie\Permission\Models\Role;

test('user invitation can be sent for an email already used by another company', function () {
    Mail::fake();

    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('create');
    grantCompanyPermissions($auth, $companyA, ['users.create', 'users.view']);

    User::factory()->create([
        'company_id' => $companyB->id,
        'email' => 'shared@example.com',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->from('/organization/users')
        ->post('/organization/users', [
            'name' => 'New Person',
            'email' => 'shared@example.com',
        ])->assertRedirect('/organization/users')
        ->assertSessionHasNoErrors();

    expect(User::query()->whereRaw('LOWER(email) = ?', ['shared@example.com'])->count())->toBe(1)
        ->and(UserInvitation::query()->where('email', 'shared@example.com')->exists())->toBeTrue();
});

test('user update rejects another users email globally', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('update');
    grantCompanyPermissions($auth, $companyA, ['users.update', 'users.view']);

    $target = User::factory()->create([
        'company_id' => $companyA->id,
        'email' => 'mine@example.com',
    ]);
    User::factory()->create([
        'company_id' => $companyB->id,
        'email' => 'theirs@example.com',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->from('/organization/users')
        ->put("/organization/users/{$target->id}", [
            'name' => $target->name,
            'email' => 'theirs@example.com',
            'password' => '',
            'status' => 'active',
        ])->assertRedirect('/organization/users')
        ->assertSessionHasErrors('email');

    expect($target->fresh()->email)->toBe('mine@example.com');
});

test('the same user can keep their own email on update', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA] = makeTwoCompaniesForUserEmailIdentity('keep');
    grantCompanyPermissions($auth, $companyA, ['users.update', 'users.view']);

    $target = User::factory()->create([
        'company_id' => $companyA->id,
        'email' => 'keep-me@example.com',
        'name' => 'Original Name',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->from('/organization/users')
        ->put("/organization/users/{$target->id}", [
            'name' => 'Renamed User',
            'email' => 'keep-me@example.com',
            'password' => '',
            'status' => 'active',
        ])->assertRedirect('/organization/users')
        ->assertSessionHasNoErrors();

    expect($target->fresh()->email)->toBe('keep-me@example.com')
        ->and($target->fresh()->name)->toBe('Renamed User');
});

test('existing user is granted another company through membership without a duplicate email identity', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('member');
    $actor = User::factory()->create(['company_id' => $companyA->id]);
    $target = User::factory()->create(['company_id' => $companyA->id]);
    $roleB = Role::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Staff',
        'guard_name' => 'web',
    ]);

    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);
    grantCompanyPermissions($actor, $companyB, ['users.update', 'users.view']);

    expect(User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $target->email)])->count())->toBe(1);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyB->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'status' => 'active',
            'role_id' => $roleB->id,
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    expect(User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $target->email)])->count())->toBe(1);

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyB->id,
        'role_id' => $roleB->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
});

test('client company_id cannot create a user invitation in another tenant', function () {
    Mail::fake();

    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('forged');
    grantCompanyPermissions($auth, $companyA, ['users.create', 'users.view']);

    $this->withSession(['current_company_id' => $companyA->id])
        ->from('/organization/users')
        ->post('/organization/users', [
            'name' => 'Forged Tenant User',
            'email' => 'forged-tenant@example.com',
            'company_id' => $companyB->id,
        ])->assertRedirect('/organization/users')
        ->assertSessionHasNoErrors();

    $invitation = UserInvitation::query()
        ->where('email', 'forged-tenant@example.com')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and((int) $invitation->company_id)->toBe($companyA->id)
        ->and((int) $invitation->company_id)->not->toBe($companyB->id)
        ->and(User::query()->where('email', 'forged-tenant@example.com')->exists())->toBeFalse();
});

test('a soft-deleted users email can receive a new invitation in another company', function () {
    Mail::fake();

    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('reuse');
    grantCompanyPermissions($auth, $companyB, ['users.create', 'users.view']);

    $deleted = User::factory()->create([
        'company_id' => $companyA->id,
        'email' => 'reusable@example.com',
    ]);
    $deleted->delete();

    $this->withSession(['current_company_id' => $companyB->id])
        ->from('/organization/users')
        ->post('/organization/users', [
            'name' => 'Replacement Identity',
            'email' => 'reusable@example.com',
        ])->assertRedirect('/organization/users')
        ->assertSessionHasNoErrors();

    expect(UserInvitation::query()->where('email', 'reusable@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'reusable@example.com')->exists())->toBeFalse()
        ->and(User::withTrashed()->whereRaw('LOWER(email) = ?', ['reusable@example.com'])->count())->toBe(1);
});

test('creating a user invitation for an employee can use an email owned by another company', function () {
    Mail::fake();

    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('emp');
    $employee = Employee::factory()->forCompany($companyA)->create([
        'status' => 'active',
    ]);
    $role = Role::query()->firstOrCreate([
        'company_id' => $companyA->id,
        'name' => 'Staff',
        'guard_name' => 'web',
    ]);

    User::factory()->create([
        'company_id' => $companyB->id,
        'email' => 'employee-taken@example.com',
    ]);

    grantCompanyPermissions($auth, $companyA, ['users.create', 'employees.update']);

    $this->withSession(['current_company_id' => $companyA->id])
        ->from("/organization/employees/{$employee->id}")
        ->post("/organization/employees/{$employee->id}/user", [
            'role_id' => $role->id,
            'email' => 'employee-taken@example.com',
            'name' => 'Employee User',
        ])->assertSessionHasNoErrors();

    expect($employee->fresh()->user_id)->toBeNull()
        ->and(UserInvitation::query()
            ->where('email', 'employee-taken@example.com')
            ->where('employee_id', $employee->id)
            ->exists())->toBeTrue();
});
