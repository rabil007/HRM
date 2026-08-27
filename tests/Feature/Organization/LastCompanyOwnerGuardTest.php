<?php

use App\Models\Company;
use App\Models\User;
use App\Support\Users\LastCompanyOwnerGuard;
use Illuminate\Support\Facades\DB;
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
