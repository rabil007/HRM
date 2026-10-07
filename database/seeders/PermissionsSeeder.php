<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Authorization\ApplicationPermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ApplicationPermissionRegistry::sync();

        $this->grantCrewAssignmentVoidPermissionToExistingRoles();
        $this->migrateRoomTypePermissionsToHotels();
    }

    /**
     * Room types are managed through Hotels. Grant equivalent hotel permissions
     * to roles that still have legacy room-type permissions.
     */
    private function migrateRoomTypePermissionsToHotels(): void
    {
        $legacyToHotel = [
            'settings.master-data.room-types.view' => 'settings.master-data.hotels.view',
            'settings.master-data.room-types.create' => 'settings.master-data.hotels.create',
            'settings.master-data.room-types.update' => 'settings.master-data.hotels.update',
            // Nested room type removal uses hotels.update, not whole-hotel delete.
            'settings.master-data.room-types.delete' => 'settings.master-data.hotels.update',
        ];

        $hotelPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_values(array_unique($legacyToHotel)))
            ->get()
            ->keyBy('name');

        $legacyPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys($legacyToHotel))
            ->get()
            ->keyBy('name');

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $teamForeignKey = config('permission.column_names.team_foreign_key') ?? 'company_id';

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->get();

        foreach ($roles as $role) {
            $names = $role->permissions()->pluck('name');

            $grantIds = [];

            foreach ($legacyToHotel as $legacy => $hotelPermission) {
                if (! $names->contains($legacy)) {
                    continue;
                }

                $permission = $hotelPermissions->get($hotelPermission);

                if ($permission !== null) {
                    $grantIds[] = $permission->id;
                }
            }

            if ($grantIds !== []) {
                $role->permissions()->syncWithoutDetaching($grantIds);
            }
        }

        foreach ($legacyToHotel as $legacyName => $hotelPermissionName) {
            $legacy = $legacyPermissions->get($legacyName);
            $hotelPermission = $hotelPermissions->get($hotelPermissionName);

            if ($legacy === null || $hotelPermission === null) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $hotelPermission->id,
                    $pivotRole => $roleId,
                ]);
            }

            $modelRows = DB::table($modelHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->get();

            foreach ($modelRows as $row) {
                $insert = [
                    $pivotPermission => $hotelPermission->id,
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

    /**
     * Privileged void: grant only to high-trust roles that already manage roles
     * (same convention as crew_operations.corrections.override).
     *
     * The seeded Owner role continues to receive all permissions via AdminSeeder::syncPermissions.
     */
    private function grantCrewAssignmentVoidPermissionToExistingRoles(): void
    {
        $voidPermission = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'crew_operations.assignments.void')
            ->first();

        if ($voidPermission === null) {
            return;
        }

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->get();

        foreach ($roles as $role) {
            $names = $role->permissions()->pluck('name');

            if (! $names->contains('roles.update')) {
                continue;
            }

            if ($names->contains('crew_operations.assignments.void')) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching([$voidPermission->id]);
        }
    }
}
