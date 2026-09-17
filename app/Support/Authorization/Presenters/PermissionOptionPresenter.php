<?php

namespace App\Support\Authorization\Presenters;

use App\Models\Permission;
use App\Support\Authorization\ApplicationPermissionRegistry;

final class PermissionOptionPresenter
{
    /**
     * @return list<array{id: int, name: string, label: string, description: string|null, group: string}>
     */
    public static function collection(iterable $permissions): array
    {
        $presented = [];

        foreach ($permissions as $permission) {
            if ($permission instanceof Permission) {
                $presented[] = self::fromModel($permission);

                continue;
            }

            if (is_array($permission)) {
                $presented[] = self::fromArray($permission);
            }
        }

        return $presented;
    }

    /**
     * @return array{id: int, name: string, label: string, description: string|null, group: string}
     */
    public static function fromModel(Permission $permission): array
    {
        $definition = ApplicationPermissionRegistry::find($permission->name);

        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => $permission->label ?: ($definition['label'] ?? self::fallbackLabel($permission->name)),
            'description' => $permission->description,
            'group' => $definition['group'] ?? self::fallbackGroup($permission->name),
        ];
    }

    /**
     * @param  array{id?: int, name: string, label?: string|null, description?: string|null}  $permission
     * @return array{id: int, name: string, label: string, description: string|null, group: string}
     */
    public static function fromArray(array $permission): array
    {
        $definition = ApplicationPermissionRegistry::find($permission['name']);

        return [
            'id' => (int) ($permission['id'] ?? 0),
            'name' => $permission['name'],
            'label' => ($permission['label'] ?? null) ?: ($definition['label'] ?? self::fallbackLabel($permission['name'])),
            'description' => $permission['description'] ?? null,
            'group' => $definition['group'] ?? self::fallbackGroup($permission['name']),
        ];
    }

    private static function fallbackLabel(string $name): string
    {
        $parts = explode('.', $name);

        if (count($parts) <= 1) {
            return $name;
        }

        return ucwords(str_replace(['.', '-', '_'], ' ', implode(' ', array_slice($parts, 1))));
    }

    private static function fallbackGroup(string $name): string
    {
        $root = explode('.', $name)[0] ?? 'other';

        return ucwords(str_replace('_', ' ', $root));
    }
}
