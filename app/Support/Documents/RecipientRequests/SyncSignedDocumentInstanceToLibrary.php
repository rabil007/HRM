<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstance;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use App\Support\EmployeeFiles\TransactionScopedPrivateFileCompensation;
use App\Support\EmployeeTrainings\TrainingCertificateDocumentIntegration;
use Illuminate\Http\UploadedFile;
use Throwable;

final class SyncSignedDocumentInstanceToLibrary
{
    public function __construct(
        private TrainingCertificateDocumentIntegration $trainingCertificateDocuments,
    ) {}

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
        TransactionScopedPrivateFileCompensation::remember($newPath, $companyId);

        try {
            $sizeBytes = (int) filesize($tempSignedPdfPath);
            $checksum = hash_file('sha256', $tempSignedPdfPath) ?: '';

            if ($oldPath !== '' && $oldPath !== $newPath) {
                EmployeeDocumentVersion::query()->create([
                    'employee_document_id' => $employeeDocument->id,
                    'company_id' => $employeeDocument->company_id,
                    'employee_id' => $employeeDocument->employee_id,
                    'version' => $employeeDocument->current_version,
                    'file_path' => $employeeDocument->file_path,
                    'original_filename' => $employeeDocument->original_filename,
                    'mime_type' => $employeeDocument->mime_type,
                    'size_bytes' => $employeeDocument->size_bytes,
                    'checksum' => $employeeDocument->checksum,
                    'uploaded_by' => $employeeDocument->uploaded_by,
                    'uploaded_at' => $employeeDocument->replaced_at ?? $employeeDocument->created_at,
                    'replaced_by' => null,
                ]);
            }

            $employeeDocument->update([
                'file_path' => $newPath,
                'size_bytes' => $sizeBytes,
                'checksum' => $checksum,
                'mime_type' => 'application/pdf',
                'current_version' => ((int) $employeeDocument->current_version) + ($oldPath !== '' && $oldPath !== $newPath ? 1 : 0),
                'replaced_at' => now(),
            ]);

            $this->trainingCertificateDocuments->syncLinkedTrainingsFromDocument($employeeDocument->refresh());
        } catch (Throwable $exception) {
            TransactionScopedPrivateFileCompensation::forget($newPath);
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
            employeeDocumentId: (int) $employeeDocument->id,
        );
    }

    public function finalizeReplacement(SignedDocumentLibraryReplacement $replacement): void
    {
        if ($replacement->oldPath === '' || $replacement->oldPath === $replacement->newPath) {
            return;
        }

        if ($this->pathStillReferenced($replacement->oldPath, $replacement->companyId)) {
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

    private function pathStillReferenced(string $path, int $companyId): bool
    {
        if (EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('file_path', $path)
            ->exists()) {
            return true;
        }

        if (EmployeeDocumentVersion::query()
            ->where('company_id', $companyId)
            ->where('file_path', $path)
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
