<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Http\UploadedFile;

final class SyncSignedDocumentInstanceToLibrary
{
    public function prepareReplacement(
        DocumentInstance $instance,
        string $tempSignedPdfPath,
        int $companyId,
    ): ?SignedDocumentLibraryReplacement {
        $instance->loadMissing('employeeDocument');

        $employeeDocument = $instance->employeeDocument;

        if (! $employeeDocument instanceof EmployeeDocument) {
            return null;
        }

        abort_unless((int) $employeeDocument->company_id === $companyId, 404);

        $oldPath = (string) $employeeDocument->file_path;

        $uploadedFile = new UploadedFile(
            $tempSignedPdfPath,
            $employeeDocument->original_filename ?: 'signed.pdf',
            'application/pdf',
            null,
            true,
        );

        $directory = dirname($oldPath);

        if ($directory === '.' || $directory === '') {
            $directory = "employee-documents/{$companyId}/{$employeeDocument->employee_id}";
        }

        $newPath = EmployeePrivateFile::store(
            $uploadedFile,
            $directory,
            [
                'upload_module' => 'employee_document',
                'company_id' => $companyId,
                'employee_id' => $employeeDocument->employee_id,
            ],
        );

        $sizeBytes = (int) filesize($tempSignedPdfPath);
        $checksum = hash_file('sha256', $tempSignedPdfPath) ?: '';

        $employeeDocument->update([
            'file_path' => $newPath,
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'mime_type' => 'application/pdf',
        ]);

        if ($oldPath === '' || $oldPath === $newPath) {
            return null;
        }

        return new SignedDocumentLibraryReplacement(
            newPath: $newPath,
            oldPath: $oldPath,
            companyId: $companyId,
        );
    }

    public function finalizeReplacement(SignedDocumentLibraryReplacement $replacement): void
    {
        EmployeePrivateFile::deleteStored(
            $replacement->oldPath,
            $replacement->companyId,
            EmployeePrivateFileKind::Document,
        );
    }

    public function rollbackReplacement(SignedDocumentLibraryReplacement $replacement): void
    {
        EmployeePrivateFile::deleteStored(
            $replacement->newPath,
            $replacement->companyId,
            EmployeePrivateFileKind::Document,
        );
    }
}
