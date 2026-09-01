<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

final class BulkDocumentsDeletionQuery
{
    /**
     * @param  list<int>  $documentIds
     * @return Collection<int, EmployeeDocument>
     */
    public static function forType(int $companyId, string $documentTypeKey, array $documentIds): Collection
    {
        if ($documentIds === []) {
            return collect();
        }

        $query = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $documentIds);

        $template = GenerateDocumentTypeKey::publishedTemplate($companyId, $documentTypeKey);

        if ($template !== null) {
            $versionId = (int) $template->published_version_id;

            return $query
                ->whereIn(
                    'id',
                    DocumentInstance::query()
                        ->where('company_id', $companyId)
                        ->where('document_generation_template_version_id', $versionId)
                        ->whereNotNull('employee_document_id')
                        ->select('employee_document_id'),
                )
                ->get();
        }

        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);

        return $query
            ->where('document_type_id', $documentType->id)
            ->get();
    }
}
