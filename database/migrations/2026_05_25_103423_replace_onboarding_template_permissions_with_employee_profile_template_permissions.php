<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const RENAMES = [
        'onboarding.templates.view' => 'employee_profile_templates.view',
        'onboarding.templates.create' => 'employee_profile_templates.create',
        'onboarding.templates.update' => 'employee_profile_templates.update',
        'onboarding.templates.delete' => 'employee_profile_templates.delete',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::RENAMES as $legacyName => $newName) {
            Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->update(['name' => $newName]);
        }

        foreach (array_values(self::RENAMES) as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_flip(self::RENAMES) as $legacyName => $newName) {
            Permission::query()
                ->where('name', $newName)
                ->where('guard_name', 'web')
                ->update(['name' => $legacyName]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
