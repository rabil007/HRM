<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'contracts.salary_revisions.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        $updatePermission = Permission::query()
            ->where('name', 'contracts.update')
            ->where('guard_name', 'web')
            ->first();

        if ($updatePermission !== null) {
            $roleHasPermissions = config('permission.table_names.role_has_permissions');
            $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
            $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $updatePermission->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permission->id,
                    $pivotRole => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        DB::table($roleHasPermissions)
            ->where($pivotPermission, $permission->id)
            ->delete();

        DB::table($modelHasPermissions)
            ->where($pivotPermission, $permission->id)
            ->delete();

        $permission->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
