<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $overview = Permission::query()->firstOrCreate([
            'name' => 'crew_operations.overview.view',
            'guard_name' => 'web',
        ]);

        $deploymentsView = Permission::query()
            ->where('name', 'crew_operations.deployments.view')
            ->where('guard_name', 'web')
            ->first();

        if ($deploymentsView === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $deploymentsView->id)
            ->pluck($pivotRole);

        foreach ($roleIds as $roleId) {
            DB::table($roleHasPermissions)->insertOrIgnore([
                $pivotPermission => $overview->id,
                $pivotRole => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('name', 'crew_operations.overview.view')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
