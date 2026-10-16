<?php

use App\Enums\PlatformAccess;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\ImpersonationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('authorized user with users.impersonate permission can start and stop impersonating an eligible user', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.view', 'users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $response = $this->post(route('organization.users.impersonate', ['user' => $target->id]));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('info');

    // Authenticated identity is now the target user
    $this->assertAuthenticatedAs($target);
    expect(session()->has('impersonation'))->toBeTrue();
    expect(session()->get('impersonation.original_user_id'))->toBe($admin->id);
    expect(session()->get('impersonation.target_user_id'))->toBe($target->id);

    // Stop impersonation
    $stopResponse = $this->post(route('organization.impersonation.stop'));
    $stopResponse->assertRedirect(route('organization.users'));
    $stopResponse->assertSessionHas('success');

    // Restores original admin
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('user without users.impersonate permission is forbidden from impersonating', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $user = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($user, $company, ['users.view'], 'RegularUser');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($user)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertForbidden();

    $this->assertAuthenticatedAs($user);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('initiator cannot impersonate themselves', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $admin->id]))
        ->assertForbidden();
});

test('cannot impersonate protected platform administrator account', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $platformAdmin = User::factory()->create([
        'status' => 'active',
        'platform_access' => PlatformAccess::Manage,
    ]);
    grantCompanyPermissions($platformAdmin, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $platformAdmin->id]))
        ->assertForbidden();
});

test('cannot impersonate account with Owner role', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $owner = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($owner, $company, ['employees.view'], 'Owner');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $owner->id]))
        ->assertForbidden();
});

test('cannot impersonate user who holds users.impersonate permission (prevent privilege chaining)', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $peerAdmin = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($peerAdmin, $company, ['users.impersonate'], 'SuperAdmin');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $peerAdmin->id]))
        ->assertForbidden();
});

test('cannot impersonate inactive or suspended user', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $suspendedUser = User::factory()->create(['status' => 'suspended']);
    grantCompanyPermissions($suspendedUser, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $suspendedUser->id]))
        ->assertStatus(422);
});

test('cannot impersonate user belonging only to another inaccessible company', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'Admin');

    $targetInB = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($targetInB, $companyB, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $targetInB->id]))
        ->assertForbidden();
});

test('missing or expired password confirmation directs admin to Fortify confirmation flow and resumes securely', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    // Admin has recent last_login_at in database but no password confirmation in session
    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            // No auth.password_confirmed_at in session
        ]);

    $response = $this->post(route('organization.users.impersonate', ['user' => $target->id]));

    // Directs to password confirmation
    $response->assertRedirect(route('password.confirm'));
    expect(session('impersonation_pending.target_user_id'))->toBe($target->id);
    expect(session('impersonation_pending.company_id'))->toBe($company->id);
    expect(session('url.intended'))->toBe(route('organization.impersonation.confirm'));

    // Confirm password via Fortify
    $confirmResponse = $this->post(route('password.confirm.store'), [
        'password' => 'password',
    ]);

    $confirmResponse->assertRedirect(route('organization.impersonation.confirm'));
    expect(session()->has('auth.password_confirmed_at'))->toBeTrue();

    // Following intended destination GET continuation renders safely without switching user
    $resumeResponse = $this->get(route('organization.impersonation.confirm'));
    $resumeResponse->assertOk();
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
    expect(session()->has('impersonation_pending'))->toBeTrue();

    // CSRF-protected POST complete finishes the transition
    $completeResponse = $this->post(route('organization.impersonation.complete'));
    $completeResponse->assertRedirect(route('dashboard'));
    $completeResponse->assertSessionHas('info');

    $this->assertAuthenticatedAs($target);
    expect(session()->has('impersonation'))->toBeTrue();
    expect(session()->get('impersonation.original_user_id'))->toBe($admin->id);
    expect(session()->get('impersonation.target_user_id'))->toBe($target->id);
    expect(session()->has('impersonation_pending'))->toBeFalse();
});

test('nested impersonation is forbidden', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target1 = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target1, $company, ['employees.view'], 'Operator');

    $target2 = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target2, $company, ['employees.view'], 'Operator2');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target1->id]))
        ->assertRedirect(route('dashboard'));

    // Attempting to impersonate another user while already impersonating
    $this->post(route('organization.users.impersonate', ['user' => $target2->id]))
        ->assertForbidden();
});

test('sensitive account operations and financial release operations are blocked during impersonation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, [
        'employees.view',
        'users.update',
        'users.create',
        'users.delete',
        'users.password_reset',
        'users.sessions.revoke',
        'roles.create',
        'roles.update',
        'roles.delete',
        'payroll.approve',
        'payroll.mark_paid',
    ], 'SuperOperator');

    $otherUser = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($otherUser, $company, ['employees.view'], 'OtherRole');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // 1. Password update & confirmation routes are blocked
    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertForbidden();

    // 2. 2FA routes are blocked
    $this->post('/user/two-factor-authentication')->assertForbidden();

    // 3. User identity mutations (update, status, destroy, store) are blocked
    $this->put(route('organization.users.update', ['user' => $otherUser->id]), [
        'name' => 'Tampered Name',
        'email' => 'tampered@example.com',
    ])->assertForbidden();

    $this->put(route('organization.users.status', ['user' => $otherUser->id]), [
        'status' => 'inactive',
    ])->assertForbidden();

    $this->delete(route('organization.users.destroy', ['user' => $otherUser->id]))
        ->assertForbidden();

    $this->post(route('organization.users.store'), [
        'name' => 'New User',
        'email' => 'newuser@example.com',
    ])->assertForbidden();

    // 4. User company memberships management is blocked
    $this->post(route('organization.users.memberships.store', ['user' => $otherUser->id]), [
        'company_id' => $company->id,
        'role' => 'Operator',
    ])->assertForbidden();

    $this->put(route('organization.users.memberships.update', ['user' => $otherUser->id, 'company' => $company->id]), [
        'role' => 'Admin',
    ])->assertForbidden();

    $this->delete(route('organization.users.memberships.destroy', ['user' => $otherUser->id, 'company' => $company->id]))
        ->assertForbidden();

    // 5. User security actions (password reset, revoking sessions) are blocked
    $this->post(route('organization.users.security.password-reset', ['user' => $otherUser->id]))
        ->assertForbidden();

    $this->post(route('organization.users.security.revoke-sessions', ['user' => $otherUser->id]))
        ->assertForbidden();

    // 6. User invitations are blocked
    $this->post(route('organization.user-invitations.store'), [
        'email' => 'invite@example.com',
        'role' => 'Operator',
    ])->assertForbidden();

    // 7. Role management is blocked
    $this->post(route('organization.roles.store'), [
        'name' => 'Privileged Hacker Role',
        'permissions' => ['users.impersonate'],
    ])->assertForbidden();

    // 8. Financial release operations are blocked
    $period = PayrollPeriod::factory()->for($company)->create();

    $this->post(route('payroll.wps.export'), [
        'period_id' => $period->id,
    ])->assertForbidden();

    $this->post(route('payroll.approve', ['payrollPeriod' => $period->id]))
        ->assertForbidden();

    $this->post(route('payroll.mark-paid', ['payrollPeriod' => $period->id]))
        ->assertForbidden();

    // 9. Verify security failure activity log was recorded with attribution
    $violationLog = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'restricted_action_blocked')
        ->latest('id')
        ->first();

    expect($violationLog)->not->toBeNull();
    expect($violationLog->properties['initiator_id'])->toBe($admin->id);
    expect($violationLog->properties['target_id'])->toBe($target->id);
});

test('expired impersonation session automatically restores original administrator', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Simulate time passing beyond expiration (120 minutes)
    $this->travel(125)->minutes();

    // Next request triggers expiration restore
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('info');

    // Follow redirect
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('actions performed during impersonation record dual-identity audit metadata', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['name' => 'Rabil Admin', 'status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['name' => 'Adam Operator', 'status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Perform an activity log action while impersonating
    activity('test-module')
        ->causedBy(Auth::user())
        ->log('Edited crew document');

    $log = Activity::query()
        ->where('log_name', 'test-module')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    // Causer is the impersonated target user
    expect($log->causer_id)->toBe($target->id);

    // Dual-identity attribution is present in properties
    $impersonationProps = $log->properties['impersonation'] ?? null;
    expect($impersonationProps)->not->toBeNull();
    expect($impersonationProps['impersonated'])->toBeTrue();
    expect($impersonationProps['original_user_id'])->toBe($admin->id);
    expect($impersonationProps['original_user_name'])->toBe('Rabil Admin');
});

test('logging out terminates impersonation session', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect(session()->has('impersonation'))->toBeFalse();
});

test('if target user is deactivated during impersonation, session safely returns to original admin', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Target user account gets deactivated in the database out-of-band
    $target->update(['status' => 'suspended']);

    // Next request: admin is authenticated and impersonation is ended
    $this->get(route('dashboard'))->assertOk();

    // Restores original admin
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('if original admin is deactivated during impersonation, session fails closed completely', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Admin account gets deactivated
    $admin->update(['status' => 'suspended']);

    // Next request triggers failClosed
    $this->get(route('dashboard'))->assertForbidden();

    $this->assertGuest();
});

test('shared impersonation data returns correct payload for Inertia frontend', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['name' => 'Original Admin', 'status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['name' => 'Target Operator', 'status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $service = app(ImpersonationService::class);

    expect($service->getSharedImpersonationData())->toBeNull();

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $shared = $service->getSharedImpersonationData(request());
    expect($shared)->not->toBeNull();
    expect($shared['is_impersonating'])->toBeTrue();
    expect($shared['original_user']['name'])->toBe('Original Admin');
    expect($shared['target_user']['name'])->toBe('Target Operator');
    expect($shared['target_user']['role_name'])->toBe('Operator');
    expect($shared['time_remaining_seconds'])->toBeGreaterThan(0);
});

test('cannot impersonate user who holds Owner role in another company (cross-company escalation prevention)', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    // Target is an ordinary operator in Company A
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'Operator');
    // But Target holds Owner role in Company B
    grantCompanyPermissions($target, $companyB, ['employees.view'], 'Owner');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertForbidden();
});

test('cannot impersonate user who holds users.impersonate directly via model_has_permissions', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    // Assign users.impersonate directly via Spatie model_has_permissions
    $perm = Permission::query()->firstOrCreate(['name' => 'users.impersonate', 'guard_name' => 'web']);
    DB::table('spatie_model_has_permissions')->insert([
        'permission_id' => $perm->id,
        'model_type' => User::class,
        'model_id' => $target->id,
        'company_id' => $company->id,
    ]);

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertForbidden();
});

test('cannot impersonate target who has administrative authority in another company where initiator has no authority', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'Operator');
    // Target has administrative authority in Company B
    grantCompanyPermissions($target, $companyB, ['roles.update'], 'AdminInB');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertForbidden();
});

test('enforces chosen company boundary during company switching while impersonating', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'Operator');
    grantCompanyPermissions($target, $companyB, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Attempting to switch to Company B while impersonating
    $this->post(route('organization.companies.switch'), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    // Verify company switch failure was logged with attribution
    $log = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'company_switch_blocked')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->properties['initiator_id'])->toBe($admin->id);
    expect($log->properties['target_id'])->toBe($target->id);
});

test('starting and stopping impersonation does not alter interactive last_login_at timestamp on either user', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $adminLastLogin = now()->subDays(2)->startOfMinute();
    $targetLastLogin = now()->subDays(4)->startOfMinute();

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => $adminLastLogin]);
    grantCompanyPermissions($admin, $company, ['users.impersonate', 'users.view'], 'Admin');

    $target = User::factory()->create(['status' => 'active', 'last_login_at' => $targetLastLogin]);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Verify last_login_at is NOT touched on either user upon starting impersonation
    expect($admin->fresh()->last_login_at->timestamp)->toBe($adminLastLogin->timestamp);
    expect($target->fresh()->last_login_at->timestamp)->toBe($targetLastLogin->timestamp);

    // Stop impersonation
    $this->post(route('organization.impersonation.stop'))
        ->assertRedirect(route('organization.users'));

    // Verify last_login_at is NOT touched on either user upon stopping impersonation
    expect($admin->fresh()->last_login_at->timestamp)->toBe($adminLastLogin->timestamp);
    expect($target->fresh()->last_login_at->timestamp)->toBe($targetLastLogin->timestamp);
});

test('impersonated session does not inherit initiator password confirmation timestamp', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Target session should not have auth.password_confirmed_at
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

test('stopping impersonation redirects to dashboard when original user lacks users.view permission', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    // Admin has users.impersonate but NOT users.view
    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'ImpersonatorOnly');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Stopping impersonation redirects to dashboard because admin lacks users.view
    $stopResponse = $this->post(route('organization.impersonation.stop'));
    $stopResponse->assertRedirect(route('dashboard'));
    $stopResponse->assertSessionHas('success');

    $this->assertAuthenticatedAs($admin);
});

test('ordinary authorized business mutation performed during impersonation succeeds with dual-identity audit', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['name' => 'Initiating Admin', 'status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['name' => 'HR Operator', 'status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view', 'employees.update'], 'HROperator');

    $employee = Employee::factory()->forCompany($company)->create([
        'name' => 'Original Employee Name',
        'employee_no' => 'EMP-0099',
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Target user performs permitted employee mutation
    $response = $this->put(route('organization.employees.update', ['employee' => $employee->id]), [
        'name' => 'Updated Employee Name',
        'employee_no' => $employee->employee_no,
        'department_id' => $employee->department_id,
        'start_date' => $employee->start_date?->toDateString() ?? now()->toDateString(),
        'status' => 'active',
    ]);

    $response->assertSessionHasNoErrors();
    expect($employee->fresh()->name)->toBe('Updated Employee Name');

    // Verify audit log has target user as causer and original admin in impersonation metadata
    $log = Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($target->id);
    expect($log->properties['impersonation']['impersonated'])->toBeTrue();
    expect($log->properties['impersonation']['original_user_id'])->toBe($admin->id);
    expect($log->properties['impersonation']['original_user_name'])->toBe('Initiating Admin');
});

test('interactive login satisfies recent authentication policy for impersonation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'password' => bcrypt('password')]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    // Authenticate via interactive login screen
    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('auth.password_confirmed_at'))->toBeTrue();

    // Set company context and immediately start impersonating
    session(['current_company_id' => $company->id]);

    $response = $this->post(route('organization.users.impersonate', ['user' => $target->id]));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('info');

    $this->assertAuthenticatedAs($target);
    expect(session()->has('impersonation'))->toBeTrue();
});

test('target user receiving Owner role during active impersonation is intercepted and session terminated with attribution', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['name' => 'Security Admin', 'status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['name' => 'Regular Operator', 'status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session()->has('impersonation'))->toBeTrue();

    // Target is elevated to Owner role in the database while impersonation is active
    grantCompanyPermissions($target, $company, [], 'Owner');

    // On subsequent request, EnsureValidImpersonationSession intercepts before action executes
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error', 'The impersonated user received protected privileges. Returned to your original account.');

    // Session has been terminated and original admin restored
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();

    // Audit trail records target_privilege_elevation_detected event with initiator attribution
    $elevationLog = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'target_privilege_elevation_detected')
        ->latest('id')
        ->first();

    expect($elevationLog)->not->toBeNull();
    expect($elevationLog->properties['initiator_id'])->toBe($admin->id);
    expect($elevationLog->properties['initiator_name'])->toBe('Security Admin');
    expect($elevationLog->properties['target_id'])->toBe($target->id);
    expect($elevationLog->properties['company_id'])->toBe($company->id);
});

test('target user receiving users.impersonate permission during active impersonation is intercepted and session terminated', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Target user directly receives users.impersonate permission
    $permission = Permission::firstOrCreate(['name' => 'users.impersonate', 'guard_name' => 'web']);
    DB::table('spatie_model_has_permissions')->insert([
        'permission_id' => $permission->id,
        'model_type' => User::class,
        'model_id' => $target->id,
        'company_id' => $company->id,
    ]);

    // On subsequent request, session terminates safely
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error');

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('strict company context is enforced and another company cannot become active for even one request', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'AdminA');
    grantCompanyPermissions($admin, $companyB, ['users.impersonate'], 'AdminB');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'OperatorA');
    grantCompanyPermissions($target, $companyB, ['employees.view'], 'OperatorB');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session('current_company_id'))->toBe($companyA->id);

    // Attempt to tamper with session current_company_id to Company B
    session(['current_company_id' => $companyB->id]);

    // Perform a request
    $response = $this->get(route('dashboard'));
    $response->assertOk();

    // Verify current_company_id was strictly restored to Company A
    expect(session('current_company_id'))->toBe($companyA->id);
    expect(request()->attributes->get('current_company_id'))->toBe($companyA->id);
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($companyA->id);
});

test('session terminates safely if target loses access to the chosen company during active impersonation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Remove target's membership from company
    DB::table('company_user')
        ->where('user_id', $target->id)
        ->where('company_id', $company->id)
        ->delete();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error', 'The impersonated user no longer has access to this company. Returned to your original account.');

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('attempting to impersonate protected account or self without recent auth immediately fails forbidden without redirecting to password confirmation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $owner = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($owner, $company, ['employees.view'], 'Owner');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            // No auth.password_confirmed_at
        ]);

    // Self impersonation fails 403
    $this->post(route('organization.users.impersonate', ['user' => $admin->id]))
        ->assertForbidden();

    // Protected owner impersonation fails 403
    $this->post(route('organization.users.impersonate', ['user' => $owner->id]))
        ->assertForbidden();

    expect(session()->has('impersonation_pending'))->toBeFalse();
});

test('resuming pending impersonation ignores client provided identity data and safely redirects when pending session missing', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $attackerTarget = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($attackerTarget, $company, ['employees.view'], 'AttackerTarget');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    // Calling confirm without pending session redirects to organization.users
    $response = $this->get(route('organization.impersonation.confirm', ['target_user_id' => $attackerTarget->id]));
    $response->assertRedirect(route('organization.users'));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('session fails closed if chosen company context is corrupted or missing during impersonation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    // Corrupt chosen company id in session
    session([
        'impersonation.impersonated_company_id' => 0,
        'impersonation.original_company_id' => 0,
    ]);

    $this->get(route('dashboard'))->assertForbidden();
    $this->assertGuest();
});

test('initiator who loses company membership cannot start impersonation even with permission', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    // Remove admin from company membership
    DB::table('company_user')
        ->where('user_id', $admin->id)
        ->where('company_id', $company->id)
        ->delete();

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertForbidden();

    expect(session()->has('impersonation'))->toBeFalse();
});

test('initiator with permission in Company B cannot impersonate a user in Company A where they lack permission', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    DB::table('company_user')->insert([
        ['user_id' => $admin->id, 'company_id' => $companyA->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $admin->id, 'company_id' => $companyB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    grantCompanyPermissions($admin, $companyA, ['users.view'], 'Viewer');
    grantCompanyPermissions($admin, $companyB, ['users.impersonate'], 'Admin');

    $targetA = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($targetA, $companyA, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $targetA->id]))
        ->assertForbidden();

    expect(session()->has('impersonation'))->toBeFalse();
});

test('initiator who loses company access during password confirmation cannot complete impersonation', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('password.confirm'));

    expect(session()->has('impersonation_pending'))->toBeTrue();

    // Confirm password
    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('organization.impersonation.confirm'));

    // Admin loses access to Company A before completing
    DB::table('company_user')
        ->where('user_id', $admin->id)
        ->where('company_id', $company->id)
        ->delete();

    $this->post(route('organization.impersonation.complete'))
        ->assertForbidden();

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
    expect(session()->has('impersonation_pending'))->toBeFalse();
});

test('initiator switching company context during pending confirmation is validated strictly against initial company', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    DB::table('company_user')->insert([
        ['user_id' => $admin->id, 'company_id' => $companyA->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $admin->id, 'company_id' => $companyB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'AdminA');
    grantCompanyPermissions($admin, $companyB, ['users.view'], 'ViewerB');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('password.confirm'));

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('organization.impersonation.confirm'));

    // Admin alters session current_company_id to Company B
    session(['current_company_id' => $companyB->id]);

    // Completing impersonation validates strictly against Company A
    $response = $this->post(route('organization.impersonation.complete'));
    $response->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session()->get('impersonation.original_company_id'))->toBe($companyA->id);
    expect(session()->get('impersonation.impersonated_company_id'))->toBe($companyA->id);
    expect(session('current_company_id'))->toBe($companyA->id);
});

test('pending impersonation is rejected if authenticated user changes before completion', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $otherUser = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($otherUser, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('password.confirm'));

    // Switch authenticated user to otherUser
    $this->actingAs($otherUser)
        ->withSession([
            'auth.password_confirmed_at' => time(),
        ]);

    $response = $this->post(route('organization.impersonation.complete'));
    $response->assertRedirect(route('organization.users'));
    $response->assertSessionHas('error', 'Initiating user mismatch.');

    $this->assertAuthenticatedAs($otherUser);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('pending impersonation expires after 10 minutes and cannot be replayed', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
            'impersonation_pending' => [
                'initiator_id' => $admin->id,
                'company_id' => $company->id,
                'target_id' => $target->id,
                'created_at' => now()->subMinutes(11)->timestamp,
                'expires_at' => now()->subMinute()->timestamp,
            ],
        ]);

    // Expired pending impersonation is rejected
    $expiredResponse = $this->post(route('organization.impersonation.complete'));
    $expiredResponse->assertRedirect(route('organization.users'));
    $expiredResponse->assertSessionHas('error');

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();

    // Fresh pending impersonation succeeds once, but replay fails
    session([
        'impersonation_pending' => [
            'initiator_id' => $admin->id,
            'company_id' => $company->id,
            'target_id' => $target->id,
            'created_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ],
    ]);

    $successResponse = $this->post(route('organization.impersonation.complete'));
    $successResponse->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($target);

    // Stop impersonation to return to admin
    $this->post(route('organization.impersonation.stop'));
    $this->assertAuthenticatedAs($admin);

    // Replay attempt fails because pending session was already consumed
    $replayResponse = $this->post(route('organization.impersonation.complete'));
    $replayResponse->assertRedirect(route('organization.users'));
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
});

test('GET continuation renders landing page and cannot change authenticated identity', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
            'impersonation_pending' => [
                'initiator_id' => $admin->id,
                'company_id' => $company->id,
                'target_id' => $target->id,
                'created_at' => now()->timestamp,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ]);

    $response = $this->get(route('organization.impersonation.confirm'));
    $response->assertOk();

    // Authenticated user remains the administrator
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
    expect(session()->has('impersonation_pending'))->toBeTrue();
});

test('original admin losing company access during active impersonation restores into fallback company and logs attribution', function () {
    $pair = makeCompanyAuthorizationPair();
    $companyA = $pair['companyA'];
    $companyB = $pair['companyB'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    DB::table('company_user')->insert([
        ['user_id' => $admin->id, 'company_id' => $companyA->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $admin->id, 'company_id' => $companyB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    grantCompanyPermissions($admin, $companyA, ['users.impersonate'], 'AdminA');
    grantCompanyPermissions($admin, $companyB, ['users.view'], 'ViewerB');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $companyA, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $companyA->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Admin loses membership in Company A (but retains Company B)
    DB::table('company_user')
        ->where('user_id', $admin->id)
        ->where('company_id', $companyA->id)
        ->delete();

    // Next request is intercepted by EnsureValidImpersonationSession
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error', 'Your access to this company was revoked. Returned to your original account.');

    // Original admin restored into Company B
    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();
    expect(session('current_company_id'))->toBe($companyB->id);

    // Audit log verifies termination with event initiator_access_revoked
    $activity = Activity::where('log_name', 'impersonation')
        ->where('event', 'initiator_access_revoked')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['initiator_id'])->toBe($admin->id);
    expect($activity->properties['company_id'])->toBe($companyA->id);
    expect($activity->properties['timestamp'])->not->toBeNull();
});

test('original admin losing access to all companies during active impersonation fails closed', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Admin loses membership in their only company
    DB::table('company_user')
        ->where('user_id', $admin->id)
        ->where('company_id', $company->id)
        ->delete();

    // Intercepted and fails closed with 403 because no company remains to restore
    $this->get(route('dashboard'))->assertForbidden();
    $this->assertGuest();
});

test('original admin losing users.impersonate permission during active impersonation terminates session and logs attribution', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    // Revoke users.impersonate from Admin in Company A
    DB::table('spatie_model_has_roles')
        ->where('model_id', $admin->id)
        ->where('company_id', $company->id)
        ->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Next request is intercepted by EnsureValidImpersonationSession
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard'));
    $response->assertSessionHas('error', 'Your impersonation permission was revoked. Returned to your original account.');

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation'))->toBeFalse();

    $activity = Activity::where('log_name', 'impersonation')
        ->where('event', 'initiator_permission_revoked')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['initiator_id'])->toBe($admin->id);
    expect($activity->properties['company_id'])->toBe($company->id);
    expect($activity->properties['timestamp'])->not->toBeNull();
});

test('pending impersonation can be cancelled', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            'impersonation_pending' => [
                'initiator_id' => $admin->id,
                'company_id' => $company->id,
                'target_id' => $target->id,
                'created_at' => now()->timestamp,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ]);

    $response = $this->post(route('organization.impersonation.cancel'));
    $response->assertRedirect(route('organization.users'));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonation_pending'))->toBeFalse();
    expect(session()->has('impersonation'))->toBeFalse();
});
