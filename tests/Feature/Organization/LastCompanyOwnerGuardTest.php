<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Users\LastCompanyOwnerGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function setupOwnerWithPermissions(User $owner, Company $company, Role $ownerRole, array $permissionNames): void
{
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $owner->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $ownerRole->givePermissionTo($permission);
    }

    $owner->syncRoles([$ownerRole]);
}

test('last active Owner cannot be deactivated via updateStatus', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner->update(['company_id' => $company->id, 'status' => 'active']);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.update']);

    // Check guard directly
    expect(LastCompanyOwnerGuard::check($owner, $company->id))->toBeFalse();

    // Check through updateStatus controller endpoint
    $response = $this->actingAs($owner)
        ->put(route('organization.users.status', $owner), [
            'status' => 'inactive',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot deactivate user: the company must have at least one active Owner.');

    expect($owner->fresh()->status)->toBe('active');
});

test('last active Owner cannot lose the Owner role', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $memberRole = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner->update(['company_id' => $company->id, 'status' => 'active']);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.update']);

    // Attempt to change role away from Owner to Member
    $response = $this->actingAs($owner)
        ->put(route('organization.users.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'role_id' => $memberRole->id,
            'status' => 'active',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot perform this action: the company must have at least one active Owner.');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($owner->fresh()->hasRole('Owner'))->toBeTrue();
});

test('last active Owner cannot be deleted', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner->update(['company_id' => $company->id, 'status' => 'active']);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.delete']);

    $response = $this->actingAs($owner)
        ->delete(route('organization.users.destroy', $owner));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot delete user: the company must have at least one active Owner.');

    expect(User::whereKey($owner->id)->exists())->toBeTrue();
});

test('last active Owner membership cannot be removed', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner->update(['status' => 'active', 'company_id' => $pair['companyB']->id]);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.update']);

    $response = $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.users.memberships.destroy', [
            'user' => $owner,
            'company' => $company,
        ]));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Cannot remove membership: the company must have at least one active Owner.');

    expect($owner->companies()->whereKey($company->id)->exists())->toBeTrue();
});

test('modifying an Owner is allowed when another active Owner exists in the company', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner1 = $pair['user'];
    $company = $pair['companyA'];

    $owner2 = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $memberRole = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner1->update(['company_id' => $company->id, 'status' => 'active']);
    setupOwnerWithPermissions($owner1, $company, $ownerRole, ['users.update']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $owner2->assignRole($ownerRole);

    expect(LastCompanyOwnerGuard::check($owner1, $company->id))->toBeTrue();

    // Changing owner1 to Member is allowed because owner2 remains an active Owner
    $response = $this->actingAs($owner1)
        ->put(route('organization.users.update', $owner1), [
            'name' => $owner1->name,
            'email' => $owner1->email,
            'role_id' => $memberRole->id,
            'status' => 'active',
        ]);

    $response->assertRedirect(route('organization.users'));
    $response->assertSessionHas('success');
});

test('Company A Owner state cannot affect Company B', function () {
    $pair = makeCompanyAuthorizationPair();
    $user = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    // User is the sole Owner of Company A
    $ownerRoleA = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $companyA->id,
    ]);
    $user->update(['company_id' => $companyA->id, 'status' => 'active']);
    setupOwnerWithPermissions($user, $companyA, $ownerRoleA, []);

    // In Company B, user has only a Member role
    $memberRoleB = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $companyB->id,
    ]);
    $user->companies()->attach($companyB->id, ['status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $user->assignRole($memberRoleB);

    // Guard check for Company A is false (cannot remove sole Owner of A)
    expect(LastCompanyOwnerGuard::check($user, $companyA->id))->toBeFalse();

    // Guard check for Company B is true (user is not an Owner in B, removing does not affect B)
    expect(LastCompanyOwnerGuard::check($user, $companyB->id))->toBeTrue();
});

test('rejected last Owner update does not leave employee linkage or user fields changed', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);
    $memberRole = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner->update([
        'company_id' => $company->id,
        'status' => 'active',
        'name' => 'Sole Owner',
        'email' => 'sole-owner@example.com',
    ]);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.update']);

    $employee = Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $originalName = $owner->name;
    $originalEmail = $owner->email;

    $this->actingAs($owner)
        ->put(route('organization.users.update', $owner), [
            'name' => 'Hijacked Name',
            'email' => 'hijacked@example.com',
            'role_id' => $memberRole->id,
            'status' => 'inactive',
            'employee_id' => $employee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot perform this action: the company must have at least one active Owner.');

    $fresh = $owner->fresh();

    expect($fresh->name)->toBe($originalName)
        ->and($fresh->email)->toBe($originalEmail)
        ->and($fresh->status)->toBe('active')
        ->and($employee->fresh()->user_id)->toBeNull();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($fresh->hasRole('Owner'))->toBeTrue();
});

test('rejected last Owner update does not replace or delete the previous avatar', function () {
    Storage::fake('public');

    $pair = makeCompanyAuthorizationPair();
    $owner = $pair['user'];
    $company = $pair['companyA'];

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);
    $memberRole = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $existingAvatar = UploadedFile::fake()->image('existing.jpg')->store('user-avatars', 'public');

    $owner->update([
        'company_id' => $company->id,
        'status' => 'active',
        'avatar' => $existingAvatar,
    ]);
    setupOwnerWithPermissions($owner, $company, $ownerRole, ['users.update']);

    $this->actingAs($owner)
        ->put(route('organization.users.update', $owner), [
            'name' => $owner->name,
            'email' => $owner->email,
            'role_id' => $memberRole->id,
            'status' => 'active',
            'avatar' => UploadedFile::fake()->image('replacement.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Cannot perform this action: the company must have at least one active Owner.');

    expect($owner->fresh()->avatar)->toBe($existingAvatar)
        ->and(Storage::disk('public')->exists($existingAvatar))->toBeTrue()
        ->and(Storage::disk('public')->files('user-avatars'))->toBe([$existingAvatar]);
});

test('non-last Owner update still applies employee linkage and identity fields', function () {
    $pair = makeCompanyAuthorizationPair();
    $owner1 = $pair['user'];
    $company = $pair['companyA'];

    $owner2 = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $ownerRole = Role::create([
        'name' => 'Owner',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);
    $memberRole = Role::create([
        'name' => 'Member',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $owner1->update(['company_id' => $company->id, 'status' => 'active', 'name' => 'First Owner']);
    setupOwnerWithPermissions($owner1, $company, $ownerRole, ['users.update']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $owner2->assignRole($ownerRole);

    $employee = Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $this->actingAs($owner1)
        ->put(route('organization.users.update', $owner1), [
            'name' => 'Updated Owner Name',
            'email' => $owner1->email,
            'role_id' => $memberRole->id,
            'status' => 'active',
            'employee_id' => $employee->id,
        ])
        ->assertRedirect(route('organization.users'))
        ->assertSessionHas('success');

    expect($owner1->fresh()->name)->toBe('Updated Owner Name')
        ->and($employee->fresh()->user_id)->toBe($owner1->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($owner1->fresh()->hasRole('Owner'))->toBeFalse()
        ->and($owner1->fresh()->hasRole('Member'))->toBeTrue();
});

test('membership-only users cannot have global identity mutated from another company', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.update']);

    $memberUser = User::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'active',
        'name' => 'Foreign Identity',
        'email' => 'foreign-identity@example.com',
    ]);
    $memberUser->companies()->attach($companyA->id, ['status' => 'active']);

    $employee = Employee::factory()->forCompany($companyA)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.users.update', $memberUser), [
            'name' => 'Mutated Identity',
            'email' => 'mutated-identity@example.com',
            'role_id' => '',
            'status' => 'inactive',
            'employee_id' => $employee->id,
        ])
        ->assertForbidden();

    $fresh = $memberUser->fresh();

    expect($fresh->name)->toBe('Foreign Identity')
        ->and($fresh->email)->toBe('foreign-identity@example.com')
        ->and($fresh->status)->toBe('active')
        ->and((int) $fresh->company_id)->toBe($companyB->id)
        ->and($employee->fresh()->user_id)->toBeNull();
});
