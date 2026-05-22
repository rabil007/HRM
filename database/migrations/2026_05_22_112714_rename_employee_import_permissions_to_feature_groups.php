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
        'employees.import.bank' => 'employees.bank_accounts.import',
        'employees.import.payroll' => 'employees.contracts.import',
        'employees.import.identity' => 'employees.identity.import',
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
