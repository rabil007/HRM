<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('settings hub is displayed for users with settings access', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.master-data.countries.view']);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/index'));
});

test('settings hub is forbidden without settings permissions', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertForbidden();
});

test('settings root no longer redirects to security', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/index'));
});
