<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const MAPPINGS = [
        'platform.settings.view' => 'settings.application.view',
        'platform.settings.update' => 'settings.application.update',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_unique(array_values(self::MAPPINGS)) as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::MAPPINGS as $sourceName => $targetName) {
            $this->copyGrants($sourceName, $targetName);
            $this->deletePermission($sourceName);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::MAPPINGS) as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::MAPPINGS as $platformName => $applicationName) {
            $this->copyGrants($applicationName, $platformName);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function copyGrants(string $sourceName, string $targetName): void
    {
        $source = Permission::query()
            ->where('name', $sourceName)
            ->where('guard_name', 'web')
            ->first();

        $target = Permission::query()
            ->where('name', $targetName)
            ->where('guard_name', 'web')
            ->first();

        if ($source === null || $target === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $teamKey = config('permission.column_names.team_foreign_key');

        $roleRows = DB::table($roleHasPermissions)
            ->where($pivotPermission, $source->id)
            ->get();

        foreach ($roleRows as $row) {
            $payload = [
                $pivotPermission => $target->id,
                $pivotRole => $row->{$pivotRole},
            ];

            if ($teamKey && property_exists($row, $teamKey)) {
                $payload[$teamKey] = $row->{$teamKey};
            }

            DB::table($roleHasPermissions)->insertOrIgnore($payload);
        }

        $modelRows = DB::table($modelHasPermissions)
            ->where($pivotPermission, $source->id)
            ->get();

        foreach ($modelRows as $row) {
            $payload = [
                $pivotPermission => $target->id,
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
            ];

            if ($teamKey && property_exists($row, $teamKey)) {
                $payload[$teamKey] = $row->{$teamKey};
            }

            DB::table($modelHasPermissions)->insertOrIgnore($payload);
        }
    }

    private function deletePermission(string $name): void
    {
        $permission = Permission::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
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
};
