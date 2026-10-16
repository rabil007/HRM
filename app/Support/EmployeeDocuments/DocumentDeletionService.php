<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\BulkDocuments\CancelPendingBulkDocumentSignatureRequests;
use App\Support\EmployeeFiles\EmployeePrivateFile;
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

        $companyId = (int) $document->company_id;
        $documentId = (int) $document->id;
        $paths = [];

        DB::transaction(function () use ($companyId, $document, &$paths): void {
            /** @var EmployeeDocument $lockedDocument */
            $lockedDocument = EmployeeDocument::query()
                ->whereKey($document->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            EmployeeTraining::query()
                ->where('company_id', $companyId)
                ->where('employee_document_id', $lockedDocument->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $linkedTraining = EmployeeTraining::query()
                ->where('company_id', $companyId)
                ->where('employee_document_id', $lockedDocument->id)
                ->exists();

            abort_if(
                $linkedTraining,
                422,
                'This document is linked to one or more training records. Unlink or delete the training associations first.',
            );

            $lockedDocument->loadMissing('versions:id,employee_document_id,file_path');

            $paths = $lockedDocument->versions
                ->pluck('file_path')
                ->push($lockedDocument->file_path)
                ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
                ->unique()
                ->values()
                ->all();

            DocumentInstance::query()
                ->where('company_id', $companyId)
                ->where('employee_document_id', $lockedDocument->id)
                ->update(['employee_document_id' => null]);

            $lockedDocument->delete();
        });

        foreach ($paths as $path) {
            if ($this->pathReferencedElsewhere($path, $companyId, $documentId)) {
                continue;
            }

            EmployeePrivateFile::deleteCompanyOwnedFile($path, $companyId);
        }
    }

    public function pathReferencedElsewhere(string $path, int $companyId, int $excludeDocumentId): bool
    {
        if (EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('file_path', $path)
            ->whereKeyNot($excludeDocumentId)
            ->exists()) {
            return true;
        }

        if (EmployeeDocumentVersion::query()
            ->where('company_id', $companyId)
            ->where('file_path', $path)
            ->where('employee_document_id', '!=', $excludeDocumentId)
            ->exists()) {
            return true;
        }

        if (EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('certificate_path', $path)
            ->exists()) {
            return true;
        }

        return EmployeeTrainingVersion::query()
            ->where('company_id', $companyId)
            ->where('file_path', $path)
            ->exists();
    }
}
