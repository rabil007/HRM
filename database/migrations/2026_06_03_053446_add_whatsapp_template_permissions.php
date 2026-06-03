<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return [
            'settings.integrations.whatsapp-templates.view',
            'settings.integrations.whatsapp-templates.create',
            'settings.integrations.whatsapp-templates.update',
            'settings.integrations.whatsapp-templates.delete',
        ];
    }

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionIds = [];

        foreach ($this->permissionNames() as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $permissionIds[$name] = $permission->id;
        }

        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $assignToRole = function (int $roleId, array $names) use ($permissionIds, $roleHasPermissions, $pivotPermission, $pivotRole): void {
            foreach ($names as $name) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permissionIds[$name],
                    $pivotRole => $roleId,
                ]);
            }
        };

        $ownerRoleIds = DB::table($rolesTable)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            $assignToRole((int) $roleId, $this->permissionNames());
        }

        $whatsappViewPermissionId = Permission::query()
            ->where('name', 'settings.integrations.whatsapp.view')
            ->where('guard_name', 'web')
            ->value('id');

        $whatsappUpdatePermissionId = Permission::query()
            ->where('name', 'settings.integrations.whatsapp.update')
            ->where('guard_name', 'web')
            ->value('id');

        $roleIdsWithWhatsappView = $whatsappViewPermissionId
            ? DB::table($roleHasPermissions)
                ->where($pivotPermission, $whatsappViewPermissionId)
                ->pluck($pivotRole)
            : collect();

        foreach ($roleIdsWithWhatsappView as $roleId) {
            $assignToRole((int) $roleId, ['settings.integrations.whatsapp-templates.view']);
        }

        $roleIdsWithWhatsappUpdate = $whatsappUpdatePermissionId
            ? DB::table($roleHasPermissions)
                ->where($pivotPermission, $whatsappUpdatePermissionId)
                ->pluck($pivotRole)
            : collect();

        foreach ($roleIdsWithWhatsappUpdate as $roleId) {
            $assignToRole((int) $roleId, [
                'settings.integrations.whatsapp-templates.view',
                'settings.integrations.whatsapp-templates.create',
                'settings.integrations.whatsapp-templates.update',
                'settings.integrations.whatsapp-templates.delete',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', $this->permissionNames())
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
