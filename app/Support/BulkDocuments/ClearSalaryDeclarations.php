<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentDeletionService;

final class ClearSalaryDeclarations
{
    public function __construct(
        private DocumentDeletionService $deletion,
    ) {}

    public function handle(int $companyId): int
    {
        $documentType = DocumentType::query()
            ->where('title', 'Salary Declaration')
            ->first();

        if ($documentType === null) {
            return 0;
        }

        $deleted = 0;

        EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('document_type_id', $documentType->id)
            ->orderBy('id')
            ->chunkById(50, function ($documents) use (&$deleted): void {
                foreach ($documents as $document) {
                    $this->deletion->delete($document);
                    $deleted++;
                }
            });

        return $deleted;
    }
}
