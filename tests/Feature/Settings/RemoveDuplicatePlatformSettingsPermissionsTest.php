<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('migration copies platform settings grants to settings application permissions and preserves team ids', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'settings.application.view',
        'settings.application.update',
        'platform.settings.view',
        'platform.settings.update',
    ] as $name) {
        Permission::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $country = Country::query()->create([
        'code' => 'MIG',
        'name' => 'Migration Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Migration Co',
        'slug' => 'migration-co-platform',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Platform Only Admin',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $user->companies()->syncWithoutDetaching([$company->id => ['status' => 'active']]);

    $platformView = Permission::query()->where('name', 'platform.settings.view')->where('guard_name', 'web')->firstOrFail();
    $platformUpdate = Permission::query()->where('name', 'platform.settings.update')->where('guard_name', 'web')->firstOrFail();
    $applicationView = Permission::query()->where('name', 'settings.application.view')->where('guard_name', 'web')->firstOrFail();
    $applicationUpdate = Permission::query()->where('name', 'settings.application.update')->where('guard_name', 'web')->firstOrFail();

    $roleHasPermissions = config('permission.table_names.role_has_permissions');
    $modelHasPermissions = config('permission.table_names.model_has_permissions');
    $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
    $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
    $teamKey = config('permission.column_names.team_foreign_key');

    DB::table($roleHasPermissions)->where($pivotRole, $role->id)->delete();

    foreach ([$platformView->id, $platformUpdate->id] as $permissionId) {
        DB::table($roleHasPermissions)->insert([
            $pivotPermission => $permissionId,
            $pivotRole => $role->id,
        ]);
    }

    DB::table($modelHasPermissions)
        ->where('model_type', $user->getMorphClass())
        ->where('model_id', $user->id)
        ->delete();

    foreach ([$platformView->id, $platformUpdate->id] as $permissionId) {
        $payload = [
            $pivotPermission => $permissionId,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->id,
        ];

        if ($teamKey) {
            $payload[$teamKey] = $company->id;
        }

        DB::table($modelHasPermissions)->insert($payload);
    }

    /** @var object{up(): void} $migration */
    $migration = require database_path('migrations/2026_07_23_104142_remove_duplicate_platform_settings_permissions.php');
    $migration->up();

    expect(Permission::query()->where('name', 'platform.settings.view')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'platform.settings.update')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'settings.application.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'settings.application.update')->exists())->toBeTrue();

    $roleGrantQuery = DB::table($roleHasPermissions)->where($pivotRole, $role->id);
    expect($roleGrantQuery->clone()->where($pivotPermission, $applicationView->id)->exists())->toBeTrue()
        ->and($roleGrantQuery->clone()->where($pivotPermission, $applicationUpdate->id)->exists())->toBeTrue()
        ->and($roleGrantQuery->clone()->whereIn($pivotPermission, [$platformView->id, $platformUpdate->id])->exists())->toBeFalse();

    $modelGrantQuery = DB::table($modelHasPermissions)
        ->where('model_type', $user->getMorphClass())
        ->where('model_id', $user->id);

    expect($modelGrantQuery->clone()->where($pivotPermission, $applicationView->id)->exists())->toBeTrue()
        ->and($modelGrantQuery->clone()->where($pivotPermission, $applicationUpdate->id)->exists())->toBeTrue()
        ->and($modelGrantQuery->clone()->whereIn($pivotPermission, [$platformView->id, $platformUpdate->id])->exists())->toBeFalse();

    if ($teamKey) {
        expect($modelGrantQuery->clone()->where($pivotPermission, $applicationView->id)->value($teamKey))->toBe($company->id)
            ->and($modelGrantQuery->clone()->where($pivotPermission, $applicationUpdate->id)->value($teamKey))->toBe($company->id);
    }

    expect($role->fresh()->company_id)->toBe($company->id);
});

test('migration down recreates platform settings permissions from settings application grants', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['settings.application.view', 'settings.application.update'] as $name) {
        Permission::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    Permission::query()
        ->whereIn('name', ['platform.settings.view', 'platform.settings.update'])
        ->where('guard_name', 'web')
        ->delete();

    $country = Country::query()->create([
        'code' => 'DWN',
        'name' => 'Down Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Down Co',
        'slug' => 'down-co-platform',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Application Admin',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions(['settings.application.view', 'settings.application.update']);

    /** @var object{down(): void} $migration */
    $migration = require database_path('migrations/2026_07_23_104142_remove_duplicate_platform_settings_permissions.php');
    $migration->down();

    expect(Permission::query()->where('name', 'platform.settings.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'platform.settings.update')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'settings.application.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'settings.application.update')->exists())->toBeTrue();

    $role->refresh()->load('permissions');
    $names = $role->permissions->pluck('name')->sort()->values()->all();

    expect($names)->toContain('platform.settings.view')
        ->and($names)->toContain('platform.settings.update')
        ->and($names)->toContain('settings.application.view')
        ->and($names)->toContain('settings.application.update');
});
