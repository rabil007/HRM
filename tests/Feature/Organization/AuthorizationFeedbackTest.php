<?php

use App\Models\User;

test('forbidden organization route returns 403', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/organization/companies')->assertForbidden();
});
