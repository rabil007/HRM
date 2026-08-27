<?php

use App\Mail\UserInvitationMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

test('authorized users can invite a user to the active company', function () {
    Mail::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.create']);

    $role = Role::create([
        'name' => 'Manager',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'newuser@example.com',
            'name' => 'New User',
            'role_id' => $role->id,
            'employee_id' => $employee->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $invitation = UserInvitation::where('email', 'newuser@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->company_id)->toBe($company->id)
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->employee_id)->toBe($employee->id)
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->token_hash)->toHaveLength(64);

    Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use ($invitation) {
        return $mail->hasTo('newuser@example.com')
            && hash('sha256', $mail->token) === $invitation->token_hash;
    });
});

test('unauthorized users cannot invite a user', function () {
    $pair = makeCompanyAuthorizationPair();
    $user = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($user, $company, ['users.view']); // Missing users.create

    $response = $this->actingAs($user)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'forbidden@example.com',
            'name' => 'Forbidden',
        ]);

    $response->assertForbidden();
    expect(UserInvitation::where('email', 'forbidden@example.com')->exists())->toBeFalse();
});

test('invitation rejects roles from another company', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.create']);

    $otherCompanyRole = Role::create([
        'name' => 'Other Role',
        'guard_name' => 'web',
        'company_id' => $companyB->id,
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'test@example.com',
            'role_id' => $otherCompanyRole->id,
        ]);

    $response->assertSessionHasErrors('role_id');
});

test('invitation rejects employees from another company', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.create']);

    $otherEmployee = Employee::factory()->forCompany($companyB)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'test@example.com',
            'employee_id' => $otherEmployee->id,
        ]);

    $response->assertSessionHasErrors('employee_id');
});

test('invitation rejects employees that are already linked to a user', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.create']);

    $existingUser = User::factory()->create(['company_id' => $company->id]);
    $linkedEmployee = Employee::factory()->forCompany($company)->create([
        'user_id' => $existingUser->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.user-invitations.store'), [
            'email' => 'test@example.com',
            'employee_id' => $linkedEmployee->id,
        ]);

    $response->assertSessionHasErrors('employee_id');
});

test('resending an invitation generates a fresh token and extends expiration', function () {
    Mail::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.create']);

    $token = 'original-plain-token';
    $invitation = UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'resend@example.com',
        'name' => 'Resend User',
        'invited_by' => $admin->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(1),
    ]);
    $originalHash = $invitation->token_hash;

    $response = $this->actingAs($admin)
        ->post(route('organization.user-invitations.resend', $invitation));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $invitation->refresh();
    expect($invitation->token_hash)->not->toBe($originalHash)
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue();

    // The original token can no longer be used
    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');
});

test('revoking an invitation marks it revoked and prevents acceptance', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.delete']);

    $token = 'token-to-revoke';
    $invitation = UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'revoke@example.com',
        'invited_by' => $admin->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($admin)
        ->delete(route('organization.user-invitations.destroy', $invitation));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect($invitation->fresh()->revoked_at)->not->toBeNull();

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');

    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'New User',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');
});

test('expired invitations cannot be accepted', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $token = 'expired-token';
    UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'expired@example.com',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');

    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'Expired User',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');
});

test('new user can accept invitation, register, and be authenticated', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $role = Role::create([
        'name' => 'Coordinator',
        'guard_name' => 'web',
        'company_id' => $company->id,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'status' => 'active',
    ]);

    $token = 'new-user-valid-token';
    $invitation = UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'newhire@example.com',
        'name' => 'New Hire',
        'role_id' => $role->id,
        'employee_id' => $employee->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/accept-invitation')
            ->where('userExists', false)
            ->where('invitation.email', 'newhire@example.com')
        );

    $response = $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'New Hire Name',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertRedirect(route('dashboard'));

    $user = User::where('email', 'newhire@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New Hire Name')
        ->and(Hash::check('Password!123', $user->password))->toBeTrue()
        ->and($employee->fresh()->user_id)->toBe($user->id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);

    // Re-acceptance is prevented
    $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'Duplicate Attempt',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');
});

test('existing user invitation requires authentication and cannot bypass login or 2FA', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyB = $pair['companyB'];

    // Existing user belongs to Company A with 2FA enabled
    $existingUser = User::factory()->withTwoFactor()->create([
        'email' => 'existing.user@example.com',
        'password' => bcrypt('existing-secret-password'),
        'status' => 'active',
    ]);

    $token = 'existing-user-token';
    $invitation = UserInvitation::create([
        'company_id' => $companyB->id,
        'email' => 'existing.user@example.com',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    // Guest visiting the accept page for an existing user sees sign-in prompt and stores intended URL
    $response = $this->get(route('invitations.accept', ['token' => $token]));
    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/accept-invitation')
            ->where('userExists', true)
            ->where('isAuthenticated', false)
        );

    expect(session('url.intended'))->toBe(route('invitations.accept', ['token' => $token]));

    // An unauthenticated attacker possessing the token CANNOT accept the invitation or log in
    $postResponse = $this->post(route('invitations.accept.store'), [
        'token' => $token,
    ]);

    $postResponse->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Please sign in to accept this invitation.');

    $this->assertGuest();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('authenticated wrong user cannot accept someone elses invitation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $wrongUser = User::factory()->create([
        'email' => 'wrong.user@example.com',
        'status' => 'active',
    ]);

    User::factory()->create([
        'email' => 'target.user@example.com',
        'status' => 'active',
    ]);

    $token = 'target-invitation-token';
    UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'target.user@example.com',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($wrongUser)
        ->post(route('invitations.accept.store'), [
            'token' => $token,
        ])
        ->assertForbidden();
});

test('authenticated matching existing user accepts company membership without password or 2FA modification', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyB = $pair['companyB'];

    $existingUser = User::factory()->withTwoFactor()->create([
        'email' => 'multicompany@example.com',
        'password' => bcrypt('my-unchanged-password'),
        'status' => 'active',
    ]);
    $originalPasswordHash = $existingUser->password;
    $originalTwoFactorSecret = $existingUser->two_factor_secret;

    $role = Role::create([
        'name' => 'Auditor',
        'guard_name' => 'web',
        'company_id' => $companyB->id,
    ]);

    $token = 'multicompany-token';
    $invitation = UserInvitation::create([
        'company_id' => $companyB->id,
        'email' => 'multicompany@example.com',
        'role_id' => $role->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($existingUser)
        ->post(route('invitations.accept.store'), [
            'token' => $token,
        ]);

    $response->assertRedirect(route('dashboard'));

    $existingUser->refresh();
    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($existingUser->password)->toBe($originalPasswordHash)
        ->and($existingUser->two_factor_secret)->toBe($originalTwoFactorSecret)
        ->and($existingUser->companies()->whereKey($companyB->id)->exists())->toBeTrue()
        ->and($existingUser->hasRole('Auditor'))->toBeTrue();
});

test('acceptance locks row and fails fast if state becomes invalid during transaction', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $token = 'stale-token';
    $invitation = UserInvitation::create([
        'company_id' => $company->id,
        'email' => 'concurrent@example.com',
        'name' => 'Concurrent User',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    // Simulate another process accepting or revoking the invitation just before the transaction lock
    $invitation->update(['accepted_at' => now()]);

    $response = $this->post(route('invitations.accept.store'), [
        'token' => $token,
        'name' => 'Concurrent User',
        'password' => 'Password!123',
        'password_confirmation' => 'Password!123',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This invitation is invalid or has expired.');

    // Execution must fail-fast without creating a duplicate user or logging in
    expect(User::where('email', 'concurrent@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});
