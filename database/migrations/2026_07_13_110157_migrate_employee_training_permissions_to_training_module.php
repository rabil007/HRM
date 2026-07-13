<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const LEGACY_TO_NEW = [
        'employees.training.manage' => [
            'training.view',
            'training.create',
            'training.update',
            'training.delete',
            'training.import',
        ],
    ];

    /**
     * @var list<string>
     */
    private const MODULE_PERMISSIONS = [
        'training.view',
        'training.create',
        'training.update',
        'training.delete',
        'training.import',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::MODULE_PERMISSIONS as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach (self::LEGACY_TO_NEW as $legacyName => $newNames) {
            $legacy = Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->first();

            if ($legacy === null) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->pluck($pivotRole);

            $newPermissionIds = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $newNames)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($newPermissionIds as $permissionId) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        $pivotPermission => $permissionId,
                        $pivotRole => $roleId,
                    ]);
                }
            }

            DB::table($roleHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->delete();

            DB::table($modelHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->delete();

            $legacy->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::LEGACY_TO_NEW) as $legacyName) {
            Permission::query()->firstOrCreate([
                'name' => $legacyName,
                'guard_name' => 'web',
            ]);
        }

        $reverseMap = [
            'training.view' => 'employees.training.manage',
            'training.create' => 'employees.training.manage',
            'training.update' => 'employees.training.manage',
            'training.delete' => 'employees.training.manage',
            'training.import' => 'employees.training.manage',
        ];

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach ($reverseMap as $newName => $legacyName) {
            $new = Permission::query()->where('name', $newName)->where('guard_name', 'web')->first();
            $legacy = Permission::query()->where('name', $legacyName)->where('guard_name', 'web')->first();

            if ($new === null || $legacy === null) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $new->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $legacy->id,
                    $pivotRole => $roleId,
                ]);
            }
        }

        foreach (self::MODULE_PERMISSIONS as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();

            if ($permission === null) {
                continue;
            }

            DB::table($roleHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->delete();

            DB::table(config('permission.table_names.model_has_permissions'))
                ->where($pivotPermission, $permission->id)
                ->delete();

            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
