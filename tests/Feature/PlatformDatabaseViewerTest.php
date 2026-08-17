<?php

use App\Models\User;
use App\Support\Platform\PlatformDatabaseCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['platform.database_viewer.enabled' => true]);
});

test('guests cannot view the database viewer', function () {
    $this->get(route('mysql.index'))->assertRedirect(route('login'));
});

test('authenticated users without platform access cannot view the database viewer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('mysql.index'))->assertForbidden();
    $this->actingAs($user)->get(route('mysql.show', ['table' => 'users']))->assertForbidden();
    $this->actingAs($user)->get(route('mysql.export', ['table' => 'users']))->assertForbidden();
});

test('tenant admins without platform access cannot browse or export tables', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'roles.update',
        'settings.application.update',
        'companies.update',
    ]);

    $this->actingAs($user)->get(route('mysql.index'))->assertForbidden();
    $this->actingAs($user)->get(route('mysql.export', ['table' => 'users']))->assertForbidden();
});

test('a team-scoped platform.database.view permission does not unlock the database viewer', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, ['platform.database.view']);

    $this->actingAs($user)->get(route('mysql.index'))->assertForbidden();
});

test('platform viewers can list allowed tables and cannot execute arbitrary sql', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('mysql.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mysql/index')
            ->has('tables')
            ->where('tables', function ($tables): bool {
                $names = collect($tables)->all();

                return in_array('users', $names, true)
                    && ! in_array('sessions', $names, true)
                    && ! in_array('password_reset_tokens', $names, true);
            }));

    $this->actingAs($user)->get('/mysql/query')->assertNotFound();
    $this->actingAs($user)
        ->post('/mysql/query/execute', ['query' => 'SELECT * FROM users'])
        ->assertNotFound();
});

test('platform viewers can browse allowed tables with secret columns redacted', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get(route('mysql.show', ['table' => 'users']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mysql/show')
            ->where('tableName', 'users')
            ->where(
                'columns',
                function ($columns): bool {
                    $names = collect($columns)->all();

                    return in_array('email', $names, true)
                        && ! in_array('password', $names, true)
                        && ! in_array('remember_token', $names, true)
                        && ! in_array('two_factor_secret', $names, true)
                        && ! in_array('two_factor_recovery_codes', $names, true);
                },
            ));

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Browsed platform database table')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe($user->id)
        ->and($activity->properties->get('action'))->toBe('platform.database.browse')
        ->and($activity->properties->get('table'))->toBe('users')
        ->and($activity->properties->has('results'))->toBeFalse();
});

test('restricted tables cannot be browsed or exported even by platform managers', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    foreach (['sessions', 'password_reset_tokens', 'jobs', 'failed_jobs'] as $table) {
        $this->actingAs($user)->get(route('mysql.show', ['table' => $table]))->assertNotFound();
        $this->actingAs($user)->get(route('mysql.export', ['table' => $table]))->assertNotFound();
    }
});

test('platform viewers can export allowed tables without secret columns', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $response = $this->actingAs($user)
        ->get(route('mysql.export', ['table' => 'users']))
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('email')
        ->and(str_contains(strtolower($csv), 'password'))->toBeFalse();

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'Exported platform database table')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('action'))->toBe('platform.database.export')
        ->and($activity->properties->get('table'))->toBe('users');
});

test('malformed table names cannot bypass the catalog', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)->get('/mysql/users%20;drop')->assertNotFound();
    $this->actingAs($user)->get(route('mysql.show', ['table' => 'users;drop table users']))->assertNotFound();
});

test('the database catalog denies secret tables and columns', function () {
    expect(PlatformDatabaseCatalog::isAllowedTable('users'))->toBeTrue()
        ->and(PlatformDatabaseCatalog::isAllowedTable('sessions'))->toBeFalse()
        ->and(PlatformDatabaseCatalog::isAllowedTable('whatsapp_settings'))->toBeFalse()
        ->and(PlatformDatabaseCatalog::isSecretColumn('users', 'password'))->toBeTrue()
        ->and(PlatformDatabaseCatalog::isSecretColumn('users', 'two_factor_recovery_codes'))->toBeTrue()
        ->and(PlatformDatabaseCatalog::isSecretColumn('users', 'email'))->toBeFalse()
        ->and(PlatformDatabaseCatalog::isSecretColumn('app_settings', 'value'))->toBeTrue()
        ->and(PlatformDatabaseCatalog::isSecretColumn('app_settings', 'key'))->toBeFalse();
});

test('platform managers still cannot execute removed sql routes', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');

    $this->actingAs($user)
        ->post('/mysql/query/execute', ['query' => 'SELECT password FROM users'])
        ->assertNotFound();

    expect(DB::table('users')->count())->toBeGreaterThan(0);
});
