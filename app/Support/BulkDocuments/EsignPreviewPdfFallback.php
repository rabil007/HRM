<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;

final class EsignPreviewPdfFallback
{
    public static function resolve(int $companyId, string $documentTypeKey): ?string
    {
        $definition = BulkDocumentTypeRegistry::find($documentTypeKey);

        $documentType = DocumentType::query()
            ->where('title', $definition['document_type_title'])
            ->first();

        if ($documentType === null) {
            return null;
        }

        $document = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('document_type_id', $documentType->id)
            ->whereNotNull('file_path')
            ->latest('id')
            ->first();

        if ($document === null) {
            return null;
        }

        $resolved = EmployeePrivateFile::resolve(
            (string) $document->file_path,
            $companyId,
            EmployeePrivateFileKind::Document,
        );

        if ($resolved === null) {
            return null;
        }

        $contents = $resolved->get();

        return is_string($contents) && str_starts_with($contents, '%PDF') ? $contents : null;
    }
}
