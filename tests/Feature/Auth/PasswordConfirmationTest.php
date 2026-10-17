<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/confirm-password'),
    );
});

test('password confirmation requires authentication', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('successful password confirmation establishes auth.password_confirmed_at', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([
            'url.intended' => route('dashboard'),
        ]);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->post(route('password.confirm.store'), [
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(session()->has('auth.password_confirmed_at'))->toBeTrue();
    expect(session('auth.password_confirmed_at'))->toBeInt();
});
