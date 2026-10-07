<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'recruitment.requirements.request_deadline_extension';

    private const GRANT_TO_EXISTING = 'recruitment.requirements.approve';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $teamForeignKey = config('permission.column_names.team_foreign_key') ?? 'company_id';

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

        $approvePermission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', self::GRANT_TO_EXISTING)
            ->first();

        if ($approvePermission !== null) {
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $approvePermission->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permission->id,
                    $pivotRole => $roleId,
                ]);
            }

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $approvePermission->id)
                ->get();

            foreach ($modelRows as $row) {
                $insert = [
                    $pivotPermission => $permission->id,
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                ];

                if (property_exists($row, $teamForeignKey)) {
                    $insert[$teamForeignKey] = $row->{$teamForeignKey};
                }

                DB::table($modelHasPermissions)->insertOrIgnore($insert);
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
            ->where('name', self::PERMISSION)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
