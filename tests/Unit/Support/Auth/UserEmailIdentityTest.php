<?php

use App\Models\User;
use App\Support\Auth\UniqueEmailUserProvider;
use App\Support\Auth\UserEmailIdentity;
use Illuminate\Support\Facades\Auth;

test('normalize follows fortify lowercase usernames', function () {
    expect(UserEmailIdentity::normalize('  Admin@Example.COM '))
        ->toBe('admin@example.com');
});

test('mask hides the local part of an email', function () {
    expect(UserEmailIdentity::mask('dup@example.com'))
        ->toBe('d***@example.com')
        ->and(UserEmailIdentity::mask('dup@example.com'))
        ->not->toBe('dup@example.com');
});

test('find unique returns the only non-deleted user', function () {
    $user = User::factory()->create(['email' => 'unique@example.com']);

    expect(app(UserEmailIdentity::class)->findUnique('Unique@Example.com')?->id)
        ->toBe($user->id);
});

test('find unique returns null when no user matches', function () {
    expect(app(UserEmailIdentity::class)->findUnique('missing@example.com'))
        ->toBeNull();
});

test('find unique returns null when multiple non-deleted users share an email', function () {
    createDuplicateEmailUsers();

    expect(app(UserEmailIdentity::class)->findUnique('dup@example.com'))
        ->toBeNull();
});

test('find unique ignores a soft-deleted duplicate and returns the live user', function () {
    $fixtures = createDuplicateEmailUsers();
    $fixtures['userB']->delete();

    expect(app(UserEmailIdentity::class)->findUnique($fixtures['email'])?->id)
        ->toBe($fixtures['userA']->id);
});

test('duplicate groups report only non-deleted identities', function () {
    $fixtures = createDuplicateEmailUsers();
    $clean = User::factory()->create(['email' => 'clean@example.com']);

    $groups = app(UserEmailIdentity::class)->duplicateGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['identity_count'])->toBe(2)
        ->and(collect($groups[0]['users'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$fixtures['userA']->id, $fixtures['userB']->id])->sort()->values()->all())
        ->and(collect($groups[0]['users'])->pluck('id'))->not->toContain($clean->id);
});

test('the web guard uses the unique email user provider', function () {
    expect(Auth::guard('web')->getProvider())->toBeInstanceOf(UniqueEmailUserProvider::class);
});
