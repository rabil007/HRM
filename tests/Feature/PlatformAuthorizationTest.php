<?php

use App\Enums\PlatformAccess;
use App\Models\User;
use App\Support\Platform\PlatformAuthorization;
use Database\Seeders\AdminSeeder;
use Database\Seeders\PermissionsSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('platform access is not mass assignable', function () {
    $user = User::factory()->create();

    $user->update(['platform_access' => 'manage']);

    expect($user->fresh()->platform_access)->toBeNull();
});

test('granting a team-scoped platform permission does not grant platform access', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['platform.database.view', 'platform.logs.view']);

    expect(PlatformAuthorization::canView($user))->toBeFalse()
        ->and(PlatformAuthorization::canManage($user))->toBeFalse();

    $this->actingAs($user)->get('/log')->assertForbidden();
    $this->actingAs($user)->get(route('jobs.index'))->assertForbidden();
});

test('tenant administrators without platform access cannot use platform tooling', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'roles.update',
        'settings.application.update',
        'companies.view',
        'companies.update',
        'companies.create',
    ]);

    $this->actingAs($user)->get('/log')->assertForbidden();
    $this->actingAs($user)->delete('/log', ['scope' => 'all'])->assertForbidden();
    $this->actingAs($user)->get(route('jobs.index'))->assertForbidden();
    $this->actingAs($user)->get(route('mysql.index'))->assertForbidden();
});

test('platform view access is independent of the current Spatie team', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, ['employees.view']);

    app(PermissionRegistrar::class)->setPermissionsTeamId(999_999);

    expect(PlatformAuthorization::canView($user->fresh()))->toBeTrue()
        ->and(PlatformAuthorization::canManage($user->fresh()))->toBeFalse();

    $this->actingAs($user)->get('/log')->assertOk();
});

test('platform manage includes view and is required for mutations', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    expect(PlatformAuthorization::canView($user))->toBeTrue()
        ->and(PlatformAuthorization::canManage($user))->toBeTrue()
        ->and(PlatformAuthorization::sharedFlags($user))->toMatchArray([
            'view' => true,
            'manage' => true,
        ]);
});

test('the database viewer is disabled in production when unset', function () {
    config(['platform.database_viewer.enabled' => null]);
    $this->app['env'] = 'production';

    expect(PlatformAuthorization::databaseViewerEnabled())->toBeFalse();

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)->get(route('mysql.index'))->assertForbidden();
});

test('permissions seeder does not create team-scoped platform tooling permissions', function () {
    $this->seed(PermissionsSeeder::class);

    expect(Permission::query()->where('name', 'like', 'platform.%')->exists())->toBeFalse();
});

test('platform:access artisan command grants and revokes user-level access', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('platform:access', ['email' => $user->email, 'level' => 'view'])
        ->assertSuccessful();

    expect($user->fresh()->platform_access)->toBe(PlatformAccess::View);

    $this->artisan('platform:access', ['email' => $user->email, 'level' => 'manage'])
        ->assertSuccessful();

    expect($user->fresh()->platform_access)->toBe(PlatformAccess::Manage);

    $this->artisan('platform:access', ['email' => $user->email, 'level' => 'revoke'])
        ->assertSuccessful();

    expect($user->fresh()->platform_access)->toBeNull();

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Updated platform access')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toBe('platform.access.set')
        ->and($activity->properties->get('level'))->toBe('revoke')
        ->and((int) $activity->properties->get('target_user_id'))->toBe($user->id);
});

test('admin seeder grants platform manage only to the seeded admin user', function () {
    $ordinary = User::factory()->create(['email' => 'owner@example.com']);
    setupCompanyWithApplicationSettingsPermissions($ordinary, ['roles.update']);

    $this->seed(AdminSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->platform_access)->toBe(PlatformAccess::Manage)
        ->and($ordinary->fresh()->platform_access)->toBeNull();
});
