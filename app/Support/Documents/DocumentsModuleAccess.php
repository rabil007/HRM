<?php

namespace App\Support\Documents;

/**
 * Resolves Documents module section visibility and a safe default landing path
 * for permission-aware navigation. Frontend can flags are UX only; route
 * middleware remains authoritative.
 */
final class DocumentsModuleAccess
{
    /**
     * @param  list<string>  $permissions
     * @return list<array{key: string, label: string, path: string}>
     */
    public static function visibleSections(array $permissions): array
    {
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
     * @param  list<string>  $permissions
     */
    public static function defaultPath(array $permissions): ?string
    {
        $sections = self::visibleSections($permissions);

        return $sections[0]['path'] ?? null;
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function canAccessModule(array $permissions): bool
    {
        return self::defaultPath($permissions) !== null;
    }
}
