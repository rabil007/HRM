<?php

use App\Models\User;
use App\Support\Auth\RememberSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

test('remembered user stays authenticated after session idle past default lifetime', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recallerName = Auth::guard('web')->getRecallerName();
    $plainCookie = $response->getCookie($recallerName);
    $sessionId = session()->getId();

    expect($plainCookie)->not->toBeNull();
    expect(session(RememberSession::SESSION_KEY))->toBeTrue();

    DB::table('sessions')->where('id', $sessionId)->update([
        'last_activity' => now()->subMinutes(121)->getTimestamp(),
    ]);

    $this->app['auth']->forgetGuards();

    config(['session.lifetime' => 120]);

    $this->withCookie($recallerName, $plainCookie->getValue())
        ->withCookie(config('session.cookie'), $sessionId)
        ->get(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(Auth::viaRemember())->toBeFalse();
    expect(session(RememberSession::SESSION_KEY))->toBeTrue();
    expect(config('session.lifetime'))->toBe(RememberSession::LIFETIME_MINUTES);
});
