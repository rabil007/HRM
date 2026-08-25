<?php

use App\Models\User;
use App\Support\Auth\RememberSession;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Features;

test('unique active email logs in successfully', function () {
    $user = User::factory()->create(['email' => 'Unique.Login@Example.com']);

    expect($user->email)->toBe('unique.login@example.com');

    $this->post(route('login.store'), [
        'email' => 'Unique.Login@Example.com',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('duplicate email rows cannot authenticate as either arbitrary row', function () {
    $fixtures = createDuplicateEmailUsers();

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $fixtures['email'],
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
    expect(Auth::id())->not->toBe($fixtures['userA']->id)
        ->and(Auth::id())->not->toBe($fixtures['userB']->id);
});

test('ambiguous login returns the normal auth failure with no duplicate leak', function () {
    $fixtures = createDuplicateEmailUsers();

    $duplicateResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => $fixtures['email'],
        'password' => 'password',
    ]);

    $duplicateResponse->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();

    $session = strtolower((string) json_encode(session()->all()));

    expect($session)
        ->not->toContain('duplicate')
        ->not->toContain('ambiguous');

    $unknownResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => 'missing-user-'.uniqid().'@example.test',
        'password' => 'password',
    ]);

    $unknownResponse->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
});

test('inactive unique users still cannot authenticate', function () {
    $user = User::factory()->inactive()->create();

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('unique active users with two factor enabled still complete login with a recovery code', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('remember-me still works for a unique active user', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertCookie(Auth::guard('web')->getRecallerName());
    expect(session(RememberSession::SESSION_KEY))->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

test('duplicate email login does not issue a remember-me cookie', function () {
    $fixtures = createDuplicateEmailUsers();

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $fixtures['email'],
        'password' => 'password',
        'remember' => '1',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
    expect($response->getCookie(Auth::guard('web')->getRecallerName()))->toBeNull();
});

test('soft-deleted duplicate cannot hijack authentication of the remaining live user', function () {
    $fixtures = createDuplicateEmailUsers();
    $fixtures['userB']->delete();

    $this->post(route('login.store'), [
        'email' => $fixtures['email'],
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($fixtures['userA']);
});

test('a soft-deleted unique user cannot authenticate', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('duplicate email failures still count toward login throttling', function () {
    $fixtures = createDuplicateEmailUsers();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => $fixtures['email'],
            'password' => 'password',
        ])->assertRedirect(route('login'));
    }

    $this->post(route('login.store'), [
        'email' => $fixtures['email'],
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});
