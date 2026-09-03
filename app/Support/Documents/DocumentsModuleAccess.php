<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Http\Request;

final class DocumentsModuleAccess
{
    public static function canViewOverview(?User $user): bool
    {
        return $user?->can('documents.view') ?? false;
    }

    public static function canViewLibrary(?User $user): bool
    {
        return self::canViewOverview($user);
    }

    public static function canViewGenerate(?User $user): bool
    {
        return $user?->can('bulk_documents.view') ?? false;
    }

    public static function canViewApprovals(?User $user): bool
    {
        return $user?->can('documents.requests.view') ?? false;
    }

    public static function canViewSigning(?User $user): bool
    {
        return ($user?->can('documents.recipient-requests.view') ?? false)
            || ($user?->can('documents.recipient-requests.respond') ?? false);
    }

    public static function canViewRequests(?User $user): bool
    {
        return self::canViewApprovals($user) || self::canViewSigning($user);
    }

    public static function canViewActivity(?User $user): bool
    {
        return self::canViewGenerate($user);
    }

    public static function canViewSystemTemplates(?User $user): bool
    {
        return self::canViewGenerate($user);
    }

    public static function canViewDocumentTypes(?User $user): bool
    {
        return $user?->can('settings.master-data.document-types.view') ?? false;
    }

    public static function canViewConfiguration(?User $user): bool
    {
        return self::canViewDocumentTypes($user);
    }

    public static function canViewCustomTemplates(?User $user): bool
    {
        return $user?->can('documents.templates.view') ?? false;
    }

    public static function canCreateCustomTemplates(?User $user): bool
    {
        return $user?->can('documents.templates.create') ?? false;
    }

    public static function canUpdateCustomTemplates(?User $user): bool
    {
        return $user?->can('documents.templates.update') ?? false;
    }

    public static function canDeleteCustomTemplates(?User $user): bool
    {
        return $user?->can('documents.templates.delete') ?? false;
    }

    public static function canViewTemplates(?User $user): bool
    {
        return self::canViewCustomTemplates($user)
            || self::canViewSystemTemplates($user)
            || self::canViewDocumentTypes($user);
    }

    public static function canEnter(?User $user): bool
    {
        return self::canViewOverview($user)
            || self::canViewGenerate($user)
            || self::canViewRequests($user)
            || self::canViewTemplates($user);
    }

    /**
     * @return array{
     *     overview: bool,
     *     library: bool,
     *     generate: bool,
     *     requests: bool,
     *     templates: bool,
     *     configuration: bool,
     *     activity: bool,
     *     document_types: bool
     * }
     */
    public static function sections(?User $user): array
    {
        return [
            'overview' => self::canViewOverview($user),
            'library' => self::canViewLibrary($user),
            'generate' => self::canViewGenerate($user),
            'requests' => self::canViewRequests($user),
            'templates' => self::canViewTemplates($user),
            'configuration' => self::canViewConfiguration($user),
            'activity' => self::canViewActivity($user),
            'document_types' => self::canViewDocumentTypes($user),
        ];
    }

    /**
     * Resolve Generate / Activity for explicit module routes first,
     * then fall back to the legacy bulk `view` query string.
     *
     * @return 'roster'|'history'
     */
    public static function resolveBulkView(Request $request): string
    {
        $moduleView = $request->route('module_view');

        if (is_string($moduleView) && $moduleView !== '') {
            return $moduleView === 'history' ? 'history' : 'roster';
        }

        return $request->query('view') === 'history' ? 'history' : 'roster';
    }

    /**
     * @return list<array{key: string, label: string, supports_esignature: bool}>
     */
    public static function systemGenerationTemplates(?User $user): array
    {
        if (! self::canViewSystemTemplates($user)) {
            return [];
        }

        return collect(BulkDocumentTypeRegistry::availableGenerationDefinitions())
            ->map(fn (array $definition): array => [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'supports_esignature' => $definition['supports_esignature'],
            ])
            ->values()
            ->all();
    }
}
