<?php

use App\Models\User;

test('forbidden organization route returns 403', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/organization/companies')->assertForbidden();
});

test('forbidden non-html request does not return an inertia json payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/organization/companies', [
        'Accept' => 'image/jpeg',
    ]);

    $response->assertForbidden();
    expect((string) $response->headers->get('Content-Type'))
        ->not->toContain('application/json');
});
