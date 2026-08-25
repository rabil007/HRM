<?php

use App\Models\User;
use App\Support\Auth\UserAccountStatus;
use Illuminate\Support\Facades\DB;

test('only active users are allowed to authenticate', function () {
    expect(UserAccountStatus::allowsAuthentication(User::factory()->create()))->toBeTrue()
        ->and(UserAccountStatus::allowsAuthentication(User::factory()->inactive()->create()))->toBeFalse()
        ->and(UserAccountStatus::allowsAuthentication(User::factory()->suspended()->create()))->toBeFalse()
        ->and(UserAccountStatus::allowsAuthentication(null))->toBeFalse();
});

test('disabling a user cycles the remember token', function () {
    $user = User::factory()->create();
    $original = $user->remember_token;

    $user->update(['status' => 'inactive']);

    expect($user->fresh()->remember_token)->not->toBe($original)
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

test('updating a user without changing status does not cycle the remember token', function () {
    $user = User::factory()->create();
    $original = $user->remember_token;

    $user->update(['name' => 'Renamed User']);

    expect($user->fresh()->remember_token)->toBe($original);
});

test('reactivating a user does not restore or cycle remembered credentials', function () {
    $user = User::factory()->inactive()->create();
    $original = $user->remember_token;

    $user->update(['status' => 'active']);

    expect($user->fresh()->remember_token)->toBe($original);
});

test('database sessions for the user are deleted when the session driver is database', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'disabled-user-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $user->update(['status' => 'suspended']);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('non-database session stores are not bulk deleted by guessing storage paths', function () {
    config(['session.driver' => 'array']);

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'array-driver-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    $user->update(['status' => 'inactive']);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});
