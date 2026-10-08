<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'recruitment.requirements.transfer_ownership';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        // Recovery capability: grant to admin-style roles that already manage roles.
        $this->grantFromSource('roles.update', [self::PERMISSION]);

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

    /**
     * @param  list<string>  $targets
     */
    private function grantFromSource(string $sourceName, array $targets): void
    {
        $source = Permission::query()
            ->where('name', $sourceName)
            ->where('guard_name', 'web')
            ->first();

        if ($source === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $teamForeignKey = config('permission.column_names.team_foreign_key') ?? 'company_id';

        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $source->id)
            ->pluck($pivotRole);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $targets)
            ->get();

        foreach ($roleIds as $roleId) {
            foreach ($permissions as $permission) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permission->id,
                    $pivotRole => $roleId,
                ]);
            }
        }

        $modelRows = DB::table($modelHasPermissions)
            ->where($pivotPermission, $source->id)
            ->get();

        foreach ($modelRows as $row) {
            foreach ($permissions as $permission) {
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
    }
};
