<?php

namespace App\Support\Documents;

use App\Models\DocumentType;
use App\Models\User;

final class DocumentGenerationTemplatePageOptions
{
    /**
     * @return array{
     *     merge_fields: list<array{key: string, label: string, category: string, sample: string}>,
     *     document_types: list<array{id: int, title: string}>,
     *     can: array{
     *         create_templates: bool,
     *         update_templates: bool
     *     }
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'merge_fields' => DocumentTemplateMergeFields::definitions(),
            'document_types' => DocumentType::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (DocumentType $type): array => [
                    'id' => $type->id,
                    'title' => $type->title,
                ])
                ->all(),
            'can' => [
                'create_templates' => DocumentsModuleAccess::canCreateCustomTemplates($user),
                'update_templates' => DocumentsModuleAccess::canUpdateCustomTemplates($user),
            ],
        ];
    }
}
