<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();
        $permissionName = 'company_documents.manage_notifications';

        DB::table('permissions')->insertOrIgnore([
            'name' => $permissionName,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', $permissionName)
            ->value('id');

        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoleIds = DB::table($rolesTable)
            ->where('guard_name', 'web')
            ->where('name', 'Owner')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            DB::table($roleHasPermissions)->insertOrIgnore([
                $pivotPermission => $permissionId,
                $pivotRole => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission as it may be assigned to roles in production.
    }
};
