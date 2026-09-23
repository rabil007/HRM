<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.close',
        'recruitment.requirements.cancel',
        'recruitment.requirements.reopen',
        'recruitment.requirements.attachments.download',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'Owner')
            ->get();

        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            foreach ($ownerRoles as $role) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permission->id,
                    $pivotRole => $role->id,
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
            ->whereIn('name', self::PERMISSIONS)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
