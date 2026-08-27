<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

test('login regenerates the session id', function () {
    $user = User::factory()->create();

    $this->get(route('login'))->assertOk();
    $before = session()->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

test('logout invalidates the authenticated session', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('password change keeps the current session authenticated', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('password change deletes other database sessions for the same user', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    DB::table('sessions')->insert([
        'id' => 'stale-other-browser',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(DB::table('sessions')->where('id', 'stale-other-browser')->count())->toBe(0);
});

test('password change rotates remember-me so a stolen recaller cannot restore the session', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recallerName = Auth::guard('web')->getRecallerName();
    $plainCookie = $response->getCookie($recallerName);
    $tokenBefore = $user->fresh()->remember_token;

    expect($plainCookie)->not->toBeNull();

    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe($tokenBefore);

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withCookie($recallerName, (string) $plainCookie->getValue())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('password change does not disable fortify two factor enrollment', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('stale session password hashes are rejected after a password change', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $sessionKey = 'password_hash_'.Auth::getDefaultDriver();
    expect(session($sessionKey))->not->toBeNull();

    $user->update(['password' => 'changed-elsewhere']);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('password reset deletes other database sessions and does not keep the user logged in', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    config(['session.driver' => 'database']);
    Notification::fake();

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'pre-reset-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'reset-password-1',
            'password_confirmation' => 'reset-password-1',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });

    $this->assertGuest();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Hash::check('reset-password-1', $user->fresh()->password))->toBeTrue();
});

test('malicious password field on user update does not change password or invalidate sessions', function () {
    config(['session.driver' => 'database']);

    $admin = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($admin, ['users.update']);
    $target = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    $tokenBefore = $target->remember_token;
    $hashBefore = $target->password;

    DB::table('sessions')->insert([
        'id' => 'target-other-session',
        'user_id' => $target->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $this->actingAs($admin)
        ->put(route('organization.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'admin-set-password',
            'password_confirmation' => 'admin-set-password',
            'role_id' => '',
            'status' => 'active',
        ])
        ->assertRedirect(route('organization.users'));

    $this->assertAuthenticatedAs($admin);

    $fresh = $target->fresh();

    expect($fresh->password)->toBe($hashBefore)
        ->and(Hash::check('admin-set-password', $fresh->password))->toBeFalse()
        ->and($fresh->remember_token)->toBe($tokenBefore)
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(1);
});
