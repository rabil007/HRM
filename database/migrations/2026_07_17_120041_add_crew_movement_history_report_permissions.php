<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'reports.crew_movement_history.view',
        'reports.crew_movement_history.export',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::NEW_PERMISSIONS)
            ->map(fn (string $name) => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));

        $source = Permission::query()
            ->where('name', 'crew_operations.assignments.view')
            ->where('guard_name', 'web')
            ->first();

        if ($source !== null) {
            $roleHasPermissions = config('permission.table_names.role_has_permissions');
            $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
            $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $source->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                foreach ($permissions as $permission) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        $pivotPermission => $permission->id,
                        $pivotRole => $roleId,
                    ]);
                }
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
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
