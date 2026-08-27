<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;

test('authorized users can send an admin password reset link to a home-company user', function () {
    Notification::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.password_reset']);

    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'target@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.users.security.password-reset', $targetUser));

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Password reset link sent to target@example.com.');

    Notification::assertSentTo($targetUser, ResetPassword::class);
});

test('unauthorized users cannot send a password reset link', function () {
    Notification::fake();

    $pair = makeCompanyAuthorizationPair();
    $user = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($user, $company, ['users.view']); // Missing users.password_reset

    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'target@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.users.security.password-reset', $targetUser));

    $response->assertForbidden();
    Notification::assertNothingSent();
});

test('administrators cannot send password reset link to a user belonging to another home company', function () {
    Notification::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.password_reset']);

    // Target user belongs natively to Company B, but has a membership in Company A
    $targetUser = User::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'active',
    ]);
    $targetUser->companies()->attach($companyA->id, ['status' => 'active']);

    $response = $this->actingAs($admin)
        ->post(route('organization.users.security.password-reset', $targetUser));

    $response->assertForbidden();
    Notification::assertNothingSent();
});

test('authorized users can revoke all sessions for a home-company user', function () {
    config(['session.driver' => 'database']);

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.sessions.revoke']);

    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    $tokenBefore = $targetUser->remember_token;

    DB::table('sessions')->insert([
        'id' => 'target-browser-session',
        'user_id' => $targetUser->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.users.security.revoke-sessions', $targetUser));

    $response->assertRedirect();
    $response->assertSessionHas('status', 'User sessions have been revoked.');

    expect($targetUser->fresh()->remember_token)->not->toBe($tokenBefore)
        ->and(DB::table('sessions')->where('user_id', $targetUser->id)->count())->toBe(0);
});

test('unauthorized users cannot revoke sessions', function () {
    $pair = makeCompanyAuthorizationPair();
    $user = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($user, $company, ['users.view']); // Missing users.sessions.revoke

    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.users.security.revoke-sessions', $targetUser));

    $response->assertForbidden();
});

test('administrators cannot revoke sessions for a user belonging to another home company', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];
    grantCompanyPermissions($admin, $companyA, ['users.sessions.revoke']);

    $targetUser = User::factory()->create([
        'company_id' => $companyB->id,
        'status' => 'active',
    ]);
    $targetUser->companies()->attach($companyA->id, ['status' => 'active']);

    $response = $this->actingAs($admin)
        ->post(route('organization.users.security.revoke-sessions', $targetUser));

    $response->assertForbidden();
});

test('users.update permission cannot mutate a user password hash', function () {
    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.update']);

    $originalHash = bcrypt('secret-original');
    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'password-guard-test@example.com',
        'password' => $originalHash,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->put("/organization/users/{$targetUser->id}", [
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'password' => 'hacked-new-password',
            'password_confirmation' => 'hacked-new-password',
            'role_id' => '',
            'status' => 'active',
        ])
        ->assertRedirect();

    // Password must not have changed
    expect($targetUser->fresh()->password)->toBe($originalHash);
});

test('failed password broker call does not create an audit log entry', function () {
    Notification::fake();

    $pair = makeCompanyAuthorizationPair();
    $admin = $pair['user'];
    $company = $pair['companyA'];
    grantCompanyPermissions($admin, $company, ['users.password_reset']);

    // Create a user, send the request, then check no activity log is created
    // when the broker cannot send (we fake the notification but don't have Mail fake,
    // so the broker will attempt and succeed — we test the opposite: when it succeeds,
    // the audit IS created (already covered). Here we verify the controller does not
    // log when broker returns a failure code by mocking Password facade.)
    Password::shouldReceive('broker')
        ->andReturn(new class
        {
            public function sendResetLink(array $credentials): string
            {
                return PasswordBroker::INVALID_USER;
            }
        });

    $targetUser = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'no-audit@example.com',
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post(route('organization.users.security.password-reset', $targetUser));

    $response->assertRedirect();
    // Should return an error flash, not a success status
    expect($response->getSession()->get('status'))->toBeNull();
    expect($response->getSession()->get('error'))->not->toBeNull();

    // No activity log for this user's password reset should exist
    $logged = Activity::query()
        ->where('description', 'sent admin password reset link')
        ->whereJsonContains('properties->target_user_id', $targetUser->id)
        ->exists();

    expect($logged)->toBeFalse();
});
