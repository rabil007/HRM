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
        'employees.documents.upload' => ['documents.upload', 'documents.view'],
        'employees.documents.delete' => ['documents.delete', 'documents.view'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['documents.view', 'documents.upload', 'documents.delete'] as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach (self::LEGACY_TO_NEW as $legacyName => $newNames) {
            $legacy = Permission::query()->where('name', $legacyName)->where('guard_name', 'web')->first();

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

            DB::table($roleHasPermissions)->where($pivotPermission, $legacy->id)->delete();
            $legacy->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['employees.documents.upload', 'employees.documents.delete'] as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $reverseMap = [
            'documents.upload' => 'employees.documents.upload',
            'documents.delete' => 'employees.documents.delete',
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

        foreach (['documents.view', 'documents.upload', 'documents.delete'] as $name) {
            Permission::query()->where('name', $name)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
