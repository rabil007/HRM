<?php

namespace App\Support\Documents;

use App\Models\User;

/**
 * Resolves Documents module section visibility and a safe default landing path
 * for permission-aware navigation. Frontend can flags are UX only; route
 * middleware remains authoritative.
 */
final class DocumentsModuleAccess
{
    /**
     * @param  User|list<string>|null  $userOrPermissions
     * @return list<string>
     */
    public static function extractPermissions(User|array|null $userOrPermissions): array
    {
        if ($userOrPermissions instanceof User) {
            return array_values(array_filter([
                $userOrPermissions->can('documents.view') ? 'documents.view' : null,
                $userOrPermissions->can('bulk_documents.view') ? 'bulk_documents.view' : null,
                $userOrPermissions->can('settings.application.view') ? 'settings.application.view' : null,
                $userOrPermissions->can('settings.application.update') ? 'settings.application.update' : null,
                $userOrPermissions->can('settings.master-data.document-types.view') ? 'settings.master-data.document-types.view' : null,
            ]));
        }

        return $userOrPermissions ?? [];
    }

    /**
     * @param  User|list<string>|null  $userOrPermissions
     * @return list<array{key: string, label: string, path: string}>
     */
    public static function visibleSections(User|array|null $userOrPermissions): array
    {
        $permissions = self::extractPermissions($userOrPermissions);
        $has = static fn (string $permission): bool => in_array($permission, $permissions, true);

        $sections = [];

        if ($has('documents.view')) {
            $sections[] = ['key' => 'overview', 'label' => 'Overview', 'path' => '/organization/documents'];
            $sections[] = ['key' => 'library', 'label' => 'Library', 'path' => '/organization/documents/library'];
        }

        if ($has('bulk_documents.view')) {
            $sections[] = ['key' => 'generate', 'label' => 'Generate & Send', 'path' => '/organization/documents/generate'];
            $sections[] = ['key' => 'requests', 'label' => 'Requests', 'path' => '/organization/documents/requests'];
        }

        if ($has('documents.view') || $has('bulk_documents.view') || $has('settings.application.view')) {
            $sections[] = ['key' => 'templates', 'label' => 'Templates', 'path' => '/organization/documents/templates'];
        }

        if ($has('bulk_documents.view')) {
            $sections[] = ['key' => 'activity', 'label' => 'Activity', 'path' => '/organization/documents/activity'];
        }

        return $sections;
    }

    /**
     * @param  User|list<string>|null  $userOrPermissions
     */
    public static function defaultPath(User|array|null $userOrPermissions): ?string
    {
        $sections = self::visibleSections($userOrPermissions);

        return $sections[0]['path'] ?? null;
    }

    /**
     * @param  User|list<string>|null  $userOrPermissions
     */
    public static function canAccessModule(User|array|null $userOrPermissions): bool
    {
        return self::defaultPath($userOrPermissions) !== null;
    }

    /**
     * @param  User|list<string>|null  $userOrPermissions
     */
    public static function canAccessSection(User|array|null $userOrPermissions, string $section): bool
    {
        foreach (self::visibleSections($userOrPermissions) as $s) {
            if ($s['key'] === $section) {
                return true;
            }
        }

        return false;
    }
}
