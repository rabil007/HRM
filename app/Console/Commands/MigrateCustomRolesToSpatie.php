<?php

namespace App\Console\Commands;

use App\Models\Role as CustomRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

#[Signature('app:migrate-custom-roles-to-spatie')]
#[Description('Migrate custom roles.permissions JSON into Spatie roles/permissions (company-scoped)')]
class MigrateCustomRolesToSpatie extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $guard = 'web';

        $customRoles = CustomRole::query()
            ->orderBy('id')
            ->get(['id', 'company_id', 'name', 'slug', 'permissions']);

        if ($customRoles->isEmpty()) {
            $this->info('No custom roles found.');

            return self::SUCCESS;
        }

        $this->info("Migrating {$customRoles->count()} roles...");

        DB::transaction(function () use ($customRoles, $guard) {
            foreach ($customRoles as $customRole) {
                $permissions = is_array($customRole->permissions) ? $customRole->permissions : [];
                $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));

                $spatieRole = Role::query()->firstOrCreate([
                    'company_id' => $customRole->company_id,
                    'name' => $customRole->slug ?: $customRole->name,
                    'guard_name' => $guard,
                ]);

                if (! empty($permissions)) {
                    $permissionIds = [];

                    foreach ($permissions as $permissionName) {
                        $permission = Permission::query()->firstOrCreate([
                            'name' => $permissionName,
                            'guard_name' => $guard,
                        ]);
                        $permissionIds[] = $permission->id;
                    }

                    $spatieRole->syncPermissions($permissionIds);
                } else {
                    $spatieRole->syncPermissions([]);
                }
            }
        });

        $this->info('Roles and permissions migrated.');

        $this->info('Done.');

        return self::SUCCESS;
    }
}
