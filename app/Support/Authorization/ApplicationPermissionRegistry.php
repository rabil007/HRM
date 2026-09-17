<?php

namespace App\Support\Authorization;

use App\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class ApplicationPermissionRegistry
{
    /**
     * @return list<array{name: string, label: string, description: string, group: string}>
     */
    public static function definitions(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $definitions = ApplicationPermissionDefinitions::all();
        $names = [];

        foreach ($definitions as $definition) {
            if (isset($names[$definition['name']])) {
                throw new \RuntimeException("Duplicate permission definition detected: {$definition['name']}");
            }

            $names[$definition['name']] = true;
        }

        return $cached = $definitions;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::definitions(), 'name');
    }

    /**
     * @return array{name: string, label: string, description: string, group: string}|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    public static function sync(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::definitions() as $definition) {
            $permission = Permission::query()->firstOrCreate(
                [
                    'name' => $definition['name'],
                    'guard_name' => 'web',
                ],
            );

            $permission->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
            ])->save();
        }
    }

    /**
     * @return list<string>
     */
    public static function placeholderDescriptionPatterns(): array
    {
        return [
            'TODO',
            'TBD',
            'Permission description',
            'Lorem ipsum',
        ];
    }
}
