<?php

use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('soft-deleted user can be re-invited in the same home company and acceptance creates a new identity', function () {
    Mail::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.create']);

    $oldRole = Role::create([
        'name' => 'Historical Manager',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $oldUser = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'maher@overseas-ms.com',
        'name' => 'Old Maher',
        'password' => bcrypt('old-password-secret'),
        'status' => 'active',
    ]);
    $oldUser->companies()->syncWithoutDetaching([
        $company->id => ['status' => 'active'],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $oldUser->syncRoles([$oldRole]);

    $oldEmployee = Employee::factory()->forCompany($company)->create([
        'user_id' => $oldUser->id,
        'status' => 'active',
    ]);

    $oldUser->delete();

    expect($oldUser->fresh()?->trashed())->toBeTrue();

    $newRole = Role::create([
        'name' => 'Replacement Staff',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $replacementEmployee = Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'maher@overseas-ms.com',
            'name' => 'New Maher',
            'role_id' => $newRole->id,
            'employee_id' => $replacementEmployee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invitation = UserInvitation::query()
        ->where('email', 'maher@overseas-ms.com')
        ->whereNull('accepted_at')
        ->whereNull('revoked_at')
        ->latest('id')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and((int) $invitation->company_id)->toBe($company->id);

    // Resolve the plain token the same way Mail assertions do in sibling tests:
    // create acceptance via a known token by issuing a fresh invitation row.
    $token = 'soft-delete-reuse-token';
    $invitation->update([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'New Maher',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('dashboard'));

    $newUser = User::query()->where('email', 'maher@overseas-ms.com')->first();

    expect($newUser)->not->toBeNull()
        ->and($newUser->id)->not->toBe($oldUser->id)
        ->and((int) $newUser->company_id)->toBe($company->id)
        ->and(Hash::check('Password!123', $newUser->password))->toBeTrue()
        ->and(Hash::check('old-password-secret', $newUser->password))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($replacementEmployee->fresh()->user_id)->toBe($newUser->id)
        ->and($oldEmployee->fresh()->user_id)->toBe($oldUser->id);

    $this->assertAuthenticatedAs($newUser);

    $oldUser = User::withTrashed()->findOrFail($oldUser->id);
    expect($oldUser->trashed())->toBeTrue()
        ->and($oldUser->companies()->whereKey($company->id)->exists())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    expect($oldUser->hasRole('Historical Manager'))->toBeTrue()
        ->and($newUser->hasRole('Historical Manager'))->toBeFalse()
        ->and($newUser->hasRole('Replacement Staff'))->toBeTrue()
        ->and($newUser->companies()->whereKey($company->id)->exists())->toBeTrue();
});

test('soft-deleted user email can be re-invited and accepted in another company', function () {
    Mail::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyB, ['users.create']);

    $deleted = User::factory()->create([
        'company_id' => $companyA->id,
        'email' => 'cross-company-reuse@example.com',
        'name' => 'Old Cross',
    ]);
    $deleted->delete();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $companyB->id])
        ->post(route('organization.user-invitations.store'), [
            'email' => 'cross-company-reuse@example.com',
            'name' => 'New Cross',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $token = 'cross-company-reuse-token';
    $invitation = UserInvitation::query()
        ->where('email', 'cross-company-reuse@example.com')
        ->where('company_id', $companyB->id)
        ->firstOrFail();
    $invitation->update([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'New Cross',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('dashboard'));

    $newUser = User::query()->where('email', 'cross-company-reuse@example.com')->first();

    expect($newUser)->not->toBeNull()
        ->and($newUser->id)->not->toBe($deleted->id)
        ->and((int) $newUser->company_id)->toBe($companyB->id)
        ->and(User::withTrashed()->find($deleted->id)?->trashed())->toBeTrue();

    $this->assertAuthenticatedAs($newUser);
});

test('mixed-case live email still cannot accept a second concurrent identity via invitation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    User::factory()->create([
        'company_id' => $company->id,
        'email' => 'Live.Owner@Example.com',
    ]);

    $token = 'mixed-case-live-token';
    UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'live.owner@example.com',
        'name' => 'Duplicate Attempt',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'Duplicate Attempt',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Please sign in to accept this invitation.');

    expect(User::query()->whereRaw('LOWER(email) = ?', ['live.owner@example.com'])->count())->toBe(1);
    $this->assertGuest();
});
