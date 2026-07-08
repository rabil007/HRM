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
    private const NEW_PERMISSIONS = [
        'bulk_documents.view',
        'bulk_documents.generate',
        'bulk_documents.delete',
        'bulk_documents.email',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $legacy = Permission::query()
            ->where('name', 'settings.application.bulk-documents')
            ->where('guard_name', 'web')
            ->first();

        if ($legacy !== null) {
            $roleHasPermissions = config('permission.table_names.role_has_permissions');
            $modelHasPermissions = config('permission.table_names.model_has_permissions');
            $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

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

        Permission::query()->firstOrCreate([
            'name' => 'settings.application.bulk-documents',
            'guard_name' => 'web',
        ]);

        foreach (self::NEW_PERMISSIONS as $name) {
            $permission = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($permission === null) {
                continue;
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
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
