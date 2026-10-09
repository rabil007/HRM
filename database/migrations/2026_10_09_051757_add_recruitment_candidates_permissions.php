<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Register candidate permissions in Spatie. Catalog entries live in
 * ApplicationPermissionDefinitions; PermissionsSeeder keeps them in sync.
 * Do not auto-grant these to existing roles (except Owner via AdminSeeder).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        'recruitment.candidates.view',
        'recruitment.candidates.create',
        'recruitment.candidates.update',
        'recruitment.candidates.move',
        'recruitment.candidates.manage',
        'recruitment.candidates.cv.download',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
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

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
