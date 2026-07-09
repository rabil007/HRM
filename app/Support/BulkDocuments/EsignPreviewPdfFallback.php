<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;

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

        $diskPath = ltrim((string) $document->file_path, '/');

        if ($diskPath === '' || ! Storage::disk('public')->exists($diskPath)) {
            return null;
        }

        $contents = Storage::disk('public')->get($diskPath);

        return is_string($contents) && str_starts_with($contents, '%PDF') ? $contents : null;
    }
}
