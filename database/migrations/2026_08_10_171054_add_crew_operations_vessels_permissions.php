<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const MODULE_PERMISSIONS = [
        'crew_operations.vessels.view',
        'crew_operations.vessels.create',
        'crew_operations.vessels.update',
        'crew_operations.vessels.delete',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TO_NEW = [
        'settings.master-data.vessels.view' => 'crew_operations.vessels.view',
        'settings.master-data.vessels.create' => 'crew_operations.vessels.create',
        'settings.master-data.vessels.update' => 'crew_operations.vessels.update',
        'settings.master-data.vessels.delete' => 'crew_operations.vessels.delete',
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

        foreach (self::LEGACY_TO_NEW as $legacyName => $newName) {
            $legacy = Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->first();

            $new = Permission::query()
                ->where('name', $newName)
                ->where('guard_name', 'web')
                ->first();

            if ($legacy === null || $new === null) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $new->id,
                    $pivotRole => $roleId,
                ]);
            }

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->get();

            foreach ($modelRows as $row) {
                DB::table($modelHasPermissions)->insertOrIgnore([
                    $pivotPermission => $new->id,
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                ]);
            }
        }

        $manningView = Permission::query()
            ->where('name', 'crew_operations.vessel_manning.view')
            ->where('guard_name', 'web')
            ->first();

        $vesselsView = Permission::query()
            ->where('name', 'crew_operations.vessels.view')
            ->where('guard_name', 'web')
            ->first();

        if ($manningView !== null && $vesselsView !== null) {
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $manningView->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $vesselsView->id,
                    $pivotRole => $roleId,
                ]);
            }

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $manningView->id)
                ->get();

            foreach ($modelRows as $row) {
                DB::table($modelHasPermissions)->insertOrIgnore([
                    $pivotPermission => $vesselsView->id,
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::MODULE_PERMISSIONS)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
