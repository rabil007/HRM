<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const LEGACY = 'contracts.salary_revisions.manage';

    /**
     * @var list<string>
     */
    private const GRANULAR = [
        'contracts.salary_revisions.view',
        'contracts.salary_revisions.create',
        'contracts.salary_revisions.update',
        'contracts.salary_revisions.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANULAR as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $legacy = Permission::query()
            ->where('name', self::LEGACY)
            ->where('guard_name', 'web')
            ->first();

        if ($legacy === null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $legacy->id)
            ->pluck($pivotRole);

        $newPermissionIds = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::GRANULAR)
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $legacy = Permission::query()->firstOrCreate([
            'name' => self::LEGACY,
            'guard_name' => 'web',
        ]);

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $create = Permission::query()
            ->where('name', 'contracts.salary_revisions.create')
            ->where('guard_name', 'web')
            ->first();

        if ($create !== null) {
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $create->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $legacy->id,
                    $pivotRole => $roleId,
                ]);
            }
        }

        foreach (self::GRANULAR as $name) {
            $permission = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($permission === null) {
                continue;
            }

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
