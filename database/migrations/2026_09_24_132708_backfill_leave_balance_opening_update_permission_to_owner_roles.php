<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Corrective backfill: grant reports.leave_balance.update_opening to protected Owner roles.
 *
 * The original create-only migration intentionally did not attach the permission to any role.
 * Owner roles cannot receive it through normal role management, so they must be backfilled here.
 */
return new class extends Migration
{
    private const PERMISSION = 'reports.leave_balance.update_opening';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'Owner')
            ->get();

        foreach ($ownerRoles as $role) {
            DB::table($roleHasPermissions)->insertOrIgnore([
                $pivotPermission => $permission->id,
                $pivotRole => $role->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', self::PERMISSION)
            ->first();

        if ($permission === null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoleIds = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'Owner')
            ->pluck('id');

        if ($ownerRoleIds->isNotEmpty()) {
            DB::table($roleHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->whereIn($pivotRole, $ownerRoleIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
