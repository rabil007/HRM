<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('forgot password works for a unique email', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('duplicate email does not arbitrarily send a password reset', function () {
    Notification::fake();

    $fixtures = createDuplicateEmailUsers();

    $unknown = $this->from(route('password.request'))
        ->post(route('password.email'), [
            'email' => 'missing-reset-'.uniqid().'@example.test',
        ]);

    $unknown->assertSessionHasErrors(['email' => trans('passwords.user')]);
    Notification::assertNothingSent();

    $duplicate = $this->from(route('password.request'))
        ->post(route('password.email'), [
            'email' => $fixtures['email'],
        ]);

    $duplicate->assertSessionHasErrors(['email' => trans('passwords.user')]);
    Notification::assertNothingSent();

    $session = strtolower((string) json_encode(session()->all()));

    expect($session)
        ->not->toContain('duplicate')
        ->not->toContain('ambiguous');
});

test('normal password reset still works for a unique user', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('duplicate email cannot complete a password reset for either user', function () {
    $fixtures = createDuplicateEmailUsers();
    $passwordA = $fixtures['userA']->password;
    $passwordB = $fixtures['userB']->password;

    $this->from(route('password.reset', 'reset-token'))
        ->post(route('password.update'), [
            'token' => 'reset-token',
            'email' => $fixtures['email'],
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertSessionHasErrors('email');

    expect($fixtures['userA']->fresh()->password)->toBe($passwordA)
        ->and($fixtures['userB']->fresh()->password)->toBe($passwordB)
        ->and(Hash::check('newpassword123', $fixtures['userA']->fresh()->password))->toBeFalse()
        ->and(Hash::check('newpassword123', $fixtures['userB']->fresh()->password))->toBeFalse();
});

test('unique password reset requests are still throttled', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => trans('passwords.throttled')]);

    Notification::assertSentTimes(ResetPassword::class, 1);
});
