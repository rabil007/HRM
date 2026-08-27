<?php

namespace App\Support\Documents\Queries;

use App\Models\DocumentGenerationTemplate;

final class DocumentGenerationTemplateQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     description: ?string,
     *     document_type_id: ?int,
     *     document_type_title: ?string,
     *     content: string,
     *     status: string,
     *     status_label: string,
     *     created_by: ?int,
     *     created_by_name: ?string,
     *     updated_by: ?int,
     *     updated_by_name: ?string,
     *     created_at: ?string,
     *     updated_at: ?string
     * }>
     */
    public static function forCompany(int $companyId): array
    {
        return DocumentGenerationTemplate::query()
            ->forCompany($companyId)
            ->with(['documentType', 'creator', 'updater'])
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentGenerationTemplate $template) => $template->toBrowseArray())
            ->values()
            ->all();
    }
}
