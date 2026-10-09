<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\AppRefresh\AuthorizationRevision;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('role permission updates bump the company authorization revision', function () {
    $admin = User::factory()->create();
    $company = createAppRefreshCompany('Roles Co');

    grantCompanyPermissions($admin, $company, ['roles.view', 'roles.update', 'employees.view', 'departments.view']);

    $before = (int) $company->fresh()->authorization_revision;

    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.roles'))
        ->put(route('organization.roles.update', $role), [
            'name' => $role->name,
            'permissions' => ['roles.view', 'roles.update', 'employees.view'],
            'employee_visibility_scope' => Role::SCOPE_ALL,
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect((int) $company->fresh()->authorization_revision)->toBe($before + 1);
});

test('role name-only updates do not bump the company authorization revision', function () {
    $admin = User::factory()->create();
    $company = createAppRefreshCompany('Name Only');

    grantCompanyPermissions($admin, $company, ['roles.view', 'roles.update', 'employees.view']);

    $before = (int) $company->fresh()->authorization_revision;

    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.roles'))
        ->put(route('organization.roles.update', $role), [
            'name' => 'Renamed Role',
            'permissions' => ['roles.view', 'roles.update', 'employees.view'],
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect((int) $company->fresh()->authorization_revision)->toBe($before);
});

test('employee visibility scope changes bump the company authorization revision', function () {
    $admin = User::factory()->create();
    $company = createAppRefreshCompany('Visibility Co');

    grantCompanyPermissions($admin, $company, ['roles.view', 'roles.update', 'employees.view']);

    $before = (int) $company->fresh()->authorization_revision;

    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.roles'))
        ->put(route('organization.roles.update', $role), [
            'name' => $role->name,
            'permissions' => ['roles.view', 'roles.update', 'employees.view'],
            'employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS,
            'department_ids' => [$department->id],
        ])
        ->assertRedirect(route('organization.roles'))
        ->assertSessionHasNoErrors();

    expect((int) $company->fresh()->authorization_revision)->toBe($before + 1);
});

test('membership role changes bump the user authorization revision', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = createAppRefreshCompany('Members Co');

    grantCompanyPermissions($admin, $company, ['users.view', 'users.update', 'roles.view', 'roles.create']);
    grantCompanyPermissions($member, $company, ['employees.view'], 'member-role');

    $before = (int) $member->fresh()->authorization_revision;

    $altRole = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'alt-member-role',
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_ALL,
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.users.memberships.update', [$member, $company]), [
            'status' => 'active',
            'role_id' => $altRole->id,
        ])
        ->assertRedirect();

    expect((int) $member->fresh()->authorization_revision)->toBeGreaterThan($before);
});

test('membership status deactivation bumps the user authorization revision', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = createAppRefreshCompany('Status Co');

    grantCompanyPermissions($admin, $company, ['users.view', 'users.update']);
    grantCompanyPermissions($member, $company, ['employees.view'], 'member-role');

    $before = (int) $member->fresh()->authorization_revision;

    $memberRole = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'member-role')
        ->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.users.memberships.update', [$member, $company]), [
            'status' => 'inactive',
            'role_id' => $memberRole->id,
        ])
        ->assertRedirect();

    expect((int) $member->fresh()->authorization_revision)->toBeGreaterThan($before);
});

test('company authorization revisions stay isolated across companies', function () {
    $companyA = createAppRefreshCompany('Company A');
    $companyB = createAppRefreshCompany('Company B');

    AuthorizationRevision::bumpCompany($companyA->id);

    expect((int) $companyA->fresh()->authorization_revision)->toBe(2)
        ->and((int) $companyB->fresh()->authorization_revision)->toBe(1);
});

test('shared permissions refresh after a company role permission change', function () {
    Cache::flush();

    $user = User::factory()->create();
    $company = createAppRefreshCompany('Perm Sync');

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', ['employees.view'])
        );

    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    Permission::query()->firstOrCreate(['name' => 'payroll.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions(['employees.view', 'payroll.view']);
    AuthorizationRevision::bumpCompany($company->id);

    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');

    $this->actingAs($user->fresh())
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('payroll.view'))
        );
});

test('backend still forbids revoked permissions on the next protected request', function () {
    $user = User::factory()->create();
    $company = createAppRefreshCompany('Revoke Co');

    grantCompanyPermissions($user, $company, ['employees.view', 'roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.roles'))
        ->assertOk();

    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions(['employees.view']);
    AuthorizationRevision::bumpCompany($company->id);

    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user->fresh())
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.roles'))
        ->assertForbidden();
});

test('authorization revision token changes when either user or company revision bumps', function () {
    $user = User::factory()->create();
    $company = createAppRefreshCompany('Token Co');

    $initial = AuthorizationRevision::current($user, $company->id);

    AuthorizationRevision::bumpUser($user);
    $afterUser = AuthorizationRevision::current($user->fresh(), $company->id);

    AuthorizationRevision::bumpCompany($company->id);
    $afterCompany = AuthorizationRevision::current($user->fresh(), $company->id);

    expect($afterUser)->not->toBe($initial)
        ->and($afterCompany)->not->toBe($afterUser)
        ->and($afterCompany)->toMatch('/^\d+\.\d+$/');
});
