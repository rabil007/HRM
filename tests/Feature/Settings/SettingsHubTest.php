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

test('settings hub is accessible to platform users without tenant permissions', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/index'));
});

test('settings hub is forbidden without settings permissions or platform access', function () {
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

test('master data hub is displayed for users with master data access', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.master-data.countries.view']);

    $this->actingAs($user)
        ->get(route('settings.master-data.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/master-data/index'));
});

test('master data hub is accessible to platform viewers', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get('/settings/master-data')
        ->assertOk();
});

test('master data hub forbids users with only other settings access', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $this->actingAs($user)
        ->get('/settings/master-data')
        ->assertForbidden();
});

test('master data hub excludes catalogs administered in other modules', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.master-data.vessels.view']);

    $this->actingAs($user)
        ->get('/settings/master-data')
        ->assertForbidden();
});
