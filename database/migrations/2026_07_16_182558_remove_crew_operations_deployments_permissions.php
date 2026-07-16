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
        'crew_operations.deployments.view' => [
            'crew_operations.assignments.view',
        ],
        'crew_operations.deployments.create' => [
            'crew_operations.assignments.view',
            'crew_operations.assignments.create',
        ],
        'crew_operations.deployments.update' => [
            'crew_operations.assignments.view',
            'crew_operations.assignments.update',
            'crew_operations.movements.perform',
        ],
        'crew_operations.deployments.delete' => [
            'crew_operations.assignments.view',
            'crew_operations.assignments.cancel',
        ],
        'crew_operations.deployments.export' => [
            'crew_operations.assignments.view',
        ],
    ];

    /**
     * @var list<string>
     */
    private const ASSIGNMENT_PERMISSIONS = [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ASSIGNMENT_PERMISSIONS as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $modelKey = config('permission.column_names.model_morph_key') ?? 'model_id';
        $teamsKey = config('permission.column_names.team_foreign_key');

        foreach (self::LEGACY_TO_NEW as $legacyName => $newNames) {
            $legacy = Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->first();

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

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->get();

            foreach ($modelRows as $modelRow) {
                foreach ($newPermissionIds as $permissionId) {
                    $payload = [
                        $pivotPermission => $permissionId,
                        $modelKey => $modelRow->{$modelKey},
                        'model_type' => $modelRow->model_type,
                    ];

                    if ($teamsKey !== null) {
                        $payload[$teamsKey] = $modelRow->{$teamsKey};
                    }

                    DB::table($modelHasPermissions)->insertOrIgnore($payload);
                }
            }

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

        foreach (array_keys(self::LEGACY_TO_NEW) as $legacyName) {
            Permission::query()->firstOrCreate([
                'name' => $legacyName,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
