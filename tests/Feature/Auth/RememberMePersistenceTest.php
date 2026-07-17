<?php

use App\Models\User;
use App\Support\Auth\RememberSession;
use Illuminate\Support\Facades\Auth;

test('remember cookie reauthenticates after session is flushed', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recallerName = Auth::guard('web')->getRecallerName();
    $response->assertCookie($recallerName);

    $plainCookie = $response->getCookie($recallerName);

    expect($plainCookie)->not->toBeNull();
    expect($plainCookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp);
    expect(session(RememberSession::SESSION_KEY))->toBeTrue();
    expect(config('session.lifetime'))->toBe(RememberSession::LIFETIME_MINUTES);

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withCookie($recallerName, $plainCookie->getValue())
        ->get(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(Auth::viaRemember())->toBeTrue();
    expect(session(RememberSession::SESSION_KEY))->toBeTrue();
});

test('without remember cookie user stays guest after session flush', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(session(RememberSession::SESSION_KEY))->toBeNull();
    expect(config('session.lifetime'))->toBe(120);

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('remembered login extends session lifetime for subsequent requests', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    config(['session.lifetime' => 120]);

    $this->get(route('dashboard'))->assertOk();

    expect(config('session.lifetime'))->toBe(RememberSession::LIFETIME_MINUTES);
    $this->assertAuthenticatedAs($user);
});
