<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Http\UploadedFile;
use Throwable;

final class SyncSignedDocumentInstanceToLibrary
{
    public function prepareReplacement(
        DocumentInstance $instance,
        string $tempSignedPdfPath,
        int $companyId,
    ): ?SignedDocumentLibraryReplacement {
        $employeeDocumentId = (int) ($instance->employee_document_id ?? 0);

        if ($employeeDocumentId <= 0) {
            return null;
        }

        $employeeDocument = EmployeeDocument::query()
            ->whereKey($employeeDocumentId)
            ->where('company_id', $companyId)
            ->where('employee_id', $instance->employee_id)
            ->lockForUpdate()
            ->first();

        if (! $employeeDocument instanceof EmployeeDocument) {
            return null;
        }

        $oldPath = (string) $employeeDocument->file_path;

        $uploadedFile = new UploadedFile(
            $tempSignedPdfPath,
            $employeeDocument->original_filename ?: 'signed.pdf',
            'application/pdf',
            null,
            true,
        );

        $validatedOldPath = EmployeePrivateFile::validatedRelativePath(
            $oldPath,
            $companyId,
            EmployeePrivateFileKind::Document,
        );
        $directory = $validatedOldPath !== null ? dirname($validatedOldPath) : '.';

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

        try {
            $sizeBytes = (int) filesize($tempSignedPdfPath);
            $checksum = hash_file('sha256', $tempSignedPdfPath) ?: '';

            $employeeDocument->update([
                'file_path' => $newPath,
                'size_bytes' => $sizeBytes,
                'checksum' => $checksum,
                'mime_type' => 'application/pdf',
            ]);
        } catch (Throwable $exception) {
            EmployeePrivateFile::deleteStored(
                $newPath,
                $companyId,
                EmployeePrivateFileKind::Document,
            );

            throw $exception;
        }

        return new SignedDocumentLibraryReplacement(
            newPath: $newPath,
            oldPath: $oldPath,
            companyId: $companyId,
        );
    }

    public function finalizeReplacement(SignedDocumentLibraryReplacement $replacement): void
    {
        if ($replacement->oldPath === '' || $replacement->oldPath === $replacement->newPath) {
            return;
        }

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
