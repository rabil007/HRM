<?php

namespace App\Support\EmployeeDocuments;

use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\CancelPendingBulkDocumentSignatureRequests;
use Illuminate\Support\Facades\Storage;

class DocumentDeletionService
{
    public function __construct(
        private CancelPendingBulkDocumentSignatureRequests $cancelPendingSignatureRequests,
    ) {}

    public function delete(EmployeeDocument $document): void
    {
        $this->cancelPendingSignatureRequests->forDocuments(
            (int) $document->company_id,
            [$document->id],
        );

        if (! str_starts_with((string) $document->file_path, 'http')) {
            Storage::disk('public')->delete((string) $document->file_path);
        }

        $document->delete();
    }
}
