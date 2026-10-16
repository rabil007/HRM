<?php

use App\Enums\PlatformAccess;
use App\Models\User;
use App\Support\Auth\ImpersonationService;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

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

test('requires recent authentication or password confirmation before impersonating', function () {
    $pair = makeCompanyAuthorizationPair();
    $company = $pair['companyA'];

    // Admin with no recent login or password confirmation
    $admin = User::factory()->create(['status' => 'active', 'last_login_at' => now()->subHours(5)]);
    grantCompanyPermissions($admin, $company, ['users.impersonate'], 'Admin');

    $target = User::factory()->create(['status' => 'active']);
    grantCompanyPermissions($target, $company, ['employees.view'], 'Operator');

    $this->actingAs($admin)
        ->withSession([
            'current_company_id' => $company->id,
            // No auth.password_confirmed_at
        ]);

    $this->post(route('organization.users.impersonate', ['user' => $target->id]))
        ->assertStatus(423);
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

test('sensitive account operations are blocked during impersonation', function () {
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

    // 1. Password update route is blocked
    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertForbidden();

    // 2. 2FA routes are blocked
    $this->post('/user/two-factor-authentication')->assertForbidden();

    // 3. User status toggle / destroy is blocked
    $this->put(route('organization.users.status', ['user' => $target->id]), [
        'status' => 'inactive',
    ])->assertForbidden();
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
