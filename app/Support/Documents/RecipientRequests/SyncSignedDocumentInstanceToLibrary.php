<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Http\UploadedFile;

final class SyncSignedDocumentInstanceToLibrary
{
    public function replaceLibraryFile(
        DocumentInstance $instance,
        string $tempSignedPdfPath,
        int $companyId,
    ): void {
        $instance->loadMissing('employeeDocument');

        $employeeDocument = $instance->employeeDocument;

        if (! $employeeDocument instanceof EmployeeDocument) {
            return;
        }

        abort_unless((int) $employeeDocument->company_id === $companyId, 404);

        $oldPath = $employeeDocument->file_path;

        $uploadedFile = new UploadedFile(
            $tempSignedPdfPath,
            $employeeDocument->original_filename ?: 'signed.pdf',
            'application/pdf',
            null,
            true,
        );

        $directory = dirname((string) $oldPath);

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

        if ($oldPath !== null && $oldPath !== $newPath) {
            EmployeePrivateFile::deleteStored((string) $oldPath, $companyId, EmployeePrivateFileKind::Document);
        }
    }
}
