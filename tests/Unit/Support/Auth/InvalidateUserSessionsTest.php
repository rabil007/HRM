<?php

use App\Models\User;
use App\Support\Auth\InvalidateUserSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

test('password change cycles the remember token', function () {
    $user = User::factory()->create();
    $original = $user->remember_token;

    $user->update(['password' => 'new-password']);

    expect($user->fresh()->remember_token)->not->toBe($original)
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

test('password change does not cycle another users remember token', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $original = $other->remember_token;

    $user->update(['password' => 'new-password']);

    expect($other->fresh()->remember_token)->toBe($original);
});

test('name-only updates do not cycle the remember token', function () {
    $user = User::factory()->create();
    $original = $user->remember_token;

    $user->update(['name' => 'Renamed User']);

    expect($user->fresh()->remember_token)->toBe($original);
});

test('password change deletes other database sessions and keeps the current session', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $session = $this->app['session']->driver();
    $session->start();
    $currentId = $session->getId();

    $request = Request::create('/settings/password', 'PUT');
    $request->setLaravelSession($session);
    $this->app->instance('request', $request);
    Auth::guard('web')->setUser($user);

    DB::table('sessions')->insert([
        [
            'id' => $currentId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => 'current',
            'last_activity' => time(),
        ],
        [
            'id' => 'other-browser-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => 'other',
            'last_activity' => time(),
        ],
        [
            'id' => 'someone-else-session',
            'user_id' => $user->id + 1,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => 'other-user',
            'last_activity' => time(),
        ],
    ]);

    $user->update(['password' => 'new-password']);

    expect(DB::table('sessions')->where('id', $currentId)->count())->toBe(1)
        ->and(DB::table('sessions')->where('id', 'other-browser-session')->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'someone-else-session')->count())->toBe(1);
});

test('password change as a guest deletes every database session for that user', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'reset-target-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    expect(Auth::guard('web')->hasUser())->toBeFalse();

    $user->update(['password' => 'reset-password']);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('non-database session stores are not bulk deleted by guessing storage paths', function () {
    config(['session.driver' => 'array']);

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'array-driver-password-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    app(InvalidateUserSessions::class)->handle($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});
