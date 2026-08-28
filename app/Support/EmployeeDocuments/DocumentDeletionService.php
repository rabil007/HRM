<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\CancelPendingBulkDocumentSignatureRequests;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Support\Facades\DB;

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

        $document->loadMissing('versions:id,employee_document_id,file_path');

        $paths = $document->versions
            ->pluck('file_path')
            ->push($document->file_path)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();

        $companyId = (int) $document->company_id;

        DB::transaction(function () use ($companyId, $document): void {
            DocumentInstance::query()
                ->where('company_id', $companyId)
                ->where('employee_document_id', $document->id)
                ->update(['employee_document_id' => null]);

            $document->delete();
        });

        foreach ($paths as $path) {
            EmployeePrivateFile::deleteStored($path, $companyId, EmployeePrivateFileKind::Document);
        }
    }
}
