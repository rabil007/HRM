<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate([
            'name' => 'documents.download',
            'guard_name' => 'web',
        ]);

        $view = Permission::query()->where('name', 'documents.view')->where('guard_name', 'web')->first();
        $download = Permission::query()->where('name', 'documents.download')->where('guard_name', 'web')->first();

        if ($view === null || $download === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $view->id)
            ->pluck($pivotRole);

        foreach ($roleIds as $roleId) {
            DB::table($roleHasPermissions)->insertOrIgnore([
                $pivotPermission => $download->id,
                $pivotRole => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('name', 'documents.download')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
