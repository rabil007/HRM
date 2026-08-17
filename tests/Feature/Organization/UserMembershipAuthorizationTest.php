<?php

use App\Enums\PlatformAccess;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{actor: User, target: User, companyA: Company, companyB: Company, roleA: Role, roleB: Role}
 */
function makeMembershipAuthorizationFixture(): array
{
    $suffix = Str::lower(Str::random(6));

    $country = Country::query()->create([
        'code' => strtoupper(substr($suffix, 0, 3)),
        'name' => 'Membership Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => strtoupper(substr($suffix, 3, 3)),
        'name' => 'Membership Currency '.$suffix,
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Alpha Membership '.$suffix,
        'slug' => 'alpha-membership-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Beta Membership '.$suffix,
        'slug' => 'beta-membership-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $roleA = Role::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Staff '.$suffix,
        'guard_name' => 'web',
    ]);

    $roleB = Role::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Staff '.$suffix,
        'guard_name' => 'web',
    ]);

    $actor = User::factory()->create(['company_id' => $companyA->id]);
    $target = User::factory()->create(['company_id' => $companyA->id]);

    return compact('actor', 'target', 'companyA', 'companyB', 'roleA', 'roleB');
}

test('authorized actor can add a user to the active company', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleA' => $roleA] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'company_id' => $companyB->id,
            'status' => 'active',
            'role_id' => $roleA->id,
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyA->id,
        'user_id' => $target->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseMissing('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
    ]);

    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyA->id,
        'role_id' => $roleA->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);

    $activity = Activity::query()
        ->where('company_id', $companyA->id)
        ->where('subject_type', User::class)
        ->where('subject_id', $target->id)
        ->where('description', 'added company membership')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->causer_id)->toBe($actor->id);
});

test('forged company_id cannot create membership in another company', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'company_id' => $companyB->id,
            'status' => 'active',
            'role_id' => $roleB->id,
        ])
        ->assertSessionHasErrors('role_id');

    $this->assertDatabaseMissing('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
    ]);
});

test('duplicate membership store updates the existing active-company pivot', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);

    $target->companies()->syncWithoutDetaching([$companyA->id => ['status' => 'active']]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'status' => 'inactive',
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    expect(DB::table('company_user')->where('company_id', $companyA->id)->where('user_id', $target->id)->count())->toBe(1)
        ->and(DB::table('company_user')->where('company_id', $companyA->id)->where('user_id', $target->id)->value('status'))->toBe('inactive');
});

test('foreign-team role cannot be assigned while active in another company', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleA' => $roleA, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);
    $target->companies()->syncWithoutDetaching([
        $companyA->id => ['status' => 'active'],
        $companyB->id => ['status' => 'active'],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $target->syncRoles([$roleB]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'company_id' => $companyB->id,
            'role_id' => $roleB->id,
        ])
        ->assertSessionHasErrors('role_id');

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post("/organization/users/{$target->id}/memberships", [
            'company_id' => $companyB->id,
            'role_id' => $roleA->id,
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyA->id,
        'role_id' => $roleA->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyB->id,
        'role_id' => $roleB->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
    expect((int) app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($companyA->id);
});

test('authorized actor can update active-company membership', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'roleA' => $roleA] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);
    $target->companies()->syncWithoutDetaching([$companyA->id => ['status' => 'active']]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/users/{$target->id}/memberships/{$companyA->id}", [
            'status' => 'inactive',
            'role_id' => $roleA->id,
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyA->id,
        'user_id' => $target->id,
        'status' => 'inactive',
    ]);
});

test('company A actor cannot update company B membership', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update']);
    $target->companies()->syncWithoutDetaching([$companyB->id => ['status' => 'active']]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/users/{$target->id}/memberships/{$companyB->id}", [
            'status' => 'inactive',
            'role_id' => $roleB->id,
        ])
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/users/{$target->id}/memberships/{$companyB->id}", [
            'status' => '',
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
        'status' => 'active',
    ]);
});

test('dual-company actor must switch before updating the other company membership', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);
    grantCompanyPermissions($actor, $companyB, ['users.update', 'users.view']);
    $target->companies()->syncWithoutDetaching([
        $companyA->id => ['status' => 'active'],
        $companyB->id => ['status' => 'active'],
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/users/{$target->id}/memberships/{$companyB->id}", [
            'status' => 'inactive',
            'role_id' => $roleB->id,
        ])
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/organization/companies/switch', ['company_id' => $companyB->id])
        ->assertRedirect();

    expect(session('current_company_id'))->toBe($companyB->id);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyB->id])
        ->put("/organization/users/{$target->id}/memberships/{$companyB->id}", [
            'status' => 'inactive',
            'role_id' => $roleB->id,
        ])
        ->assertRedirect("/organization/users/{$target->id}");

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
        'status' => 'inactive',
    ]);
});

test('authorized actor can remove active-company membership without touching another tenant', function () {
    ['actor' => $actor, 'target' => $target, 'companyA' => $companyA, 'companyB' => $companyB, 'roleA' => $roleA, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.update', 'users.view']);
    $target->companies()->syncWithoutDetaching([
        $companyA->id => ['status' => 'active'],
        $companyB->id => ['status' => 'active'],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyA->id);
    $target->syncRoles([$roleA]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $target->syncRoles([$roleB]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/organization/users/{$target->id}/memberships/{$companyB->id}", [
            'company_id' => $companyB->id,
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('company_user', [
        'company_id' => $companyB->id,
        'user_id' => $target->id,
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/organization/users/{$target->id}/memberships/{$companyA->id}")
        ->assertRedirect("/organization/users/{$target->id}");

    $this->assertDatabaseMissing('company_user', [
        'company_id' => $companyA->id,
        'user_id' => $target->id,
    ]);
    $this->assertDatabaseMissing('spatie_model_has_roles', [
        'company_id' => $companyA->id,
        'role_id' => $roleA->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $companyB->id,
        'role_id' => $roleB->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
});

test('users index does not serialize another company home users or roles', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB, 'roleB' => $roleB] = makeMembershipAuthorizationFixture();
    grantCompanyPermissions($actor, $companyA, ['users.view']);

    $outsider = User::factory()->create(['company_id' => $companyB->id, 'name' => 'Outsider Tenant User']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $outsider->syncRoles([$roleB]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/users')
            ->where('users', fn ($users) => collect($users)->pluck('id')->doesntContain($outsider->id))
            ->where('roles', fn ($roles) => collect($roles)->pluck('id')->doesntContain($roleB->id)));
});

test('platform access without membership cannot manage tenant memberships', function () {
    ['target' => $target, 'companyA' => $companyA] = makeMembershipAuthorizationFixture();
    $platformUser = User::factory()->create(['company_id' => null]);
    $platformUser->forceFill(['platform_access' => PlatformAccess::Manage])->save();

    $this->actingAs($platformUser)
        ->withSession([])
        ->post("/organization/users/{$target->id}/memberships", [
            'company_id' => $companyA->id,
            'status' => 'active',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('company_user', [
        'company_id' => $companyA->id,
        'user_id' => $target->id,
    ]);
});
