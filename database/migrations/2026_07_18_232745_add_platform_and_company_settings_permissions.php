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
    private const GRANTS = [
        'settings.application.view' => [
            'platform.settings.view',
        ],
        'settings.application.update' => [
            'platform.settings.update',
        ],
        'companies.view' => [
            'company.settings.view',
            'company.document-settings.view',
        ],
        'companies.update' => [
            'company.settings.update',
            'company.document-settings.update',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allNew = collect(self::GRANTS)->flatten()->unique()->values();

        foreach ($allNew as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $teamKey = config('permission.column_names.team_foreign_key');

        foreach (self::GRANTS as $sourceName => $targets) {
            $source = Permission::query()
                ->where('name', $sourceName)
                ->where('guard_name', 'web')
                ->first();

            if ($source === null) {
                continue;
            }

            $targetIds = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $targets)
                ->pluck('id');

            $roleRows = DB::table($roleHasPermissions)
                ->where($pivotPermission, $source->id)
                ->get();

            foreach ($roleRows as $row) {
                foreach ($targetIds as $permissionId) {
                    $payload = [
                        $pivotPermission => $permissionId,
                        $pivotRole => $row->{$pivotRole},
                    ];

                    if ($teamKey && property_exists($row, $teamKey)) {
                        $payload[$teamKey] = $row->{$teamKey};
                    }

                    DB::table($roleHasPermissions)->insertOrIgnore($payload);
                }
            }

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $source->id)
                ->get();

            foreach ($modelRows as $row) {
                foreach ($targetIds as $permissionId) {
                    $payload = [
                        $pivotPermission => $permissionId,
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                    ];

                    if ($teamKey && property_exists($row, $teamKey)) {
                        $payload[$teamKey] = $row->{$teamKey};
                    }

                    DB::table($modelHasPermissions)->insertOrIgnore($payload);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = collect(self::GRANTS)->flatten()->unique()->values();
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        foreach ($names as $name) {
            $permission = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($permission === null) {
                continue;
            }

            DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
            DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
