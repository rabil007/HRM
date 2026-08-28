<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\StoredGeneratedLibraryDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class SyncGeneratedEmployeeDocument
{
    /**
     * Store the library PDF file to disk.
     * The returned StoredGeneratedLibraryDocument provides the file path
     * to the caller for compensation before any database row is created.
     */
    public function storeLibraryFile(
        Employee $employee,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        string $tempPdfPath,
        int $companyId,
    ): StoredGeneratedLibraryDocument {
        $template->loadMissing('documentType');
        $documentType = $template->documentType;

        $folderSegment = $documentType instanceof DocumentType
            ? 'type-'.$documentType->id.'-'.Str::slug(Str::limit($documentType->title, 40, ''))
            : 'uncategorized';

        $filename = Str::slug($template->name).'-v'.$version->version.'-'.Str::slug($employee->employee_no ?: (string) $employee->id).'.pdf';

        $uploadedFile = new UploadedFile(
            $tempPdfPath,
            $filename,
            'application/pdf',
            null,
            true,
        );

        $path = EmployeePrivateFile::store(
            $uploadedFile,
            "employee-documents/{$companyId}/{$employee->id}/".Str::slug($folderSegment),
            [
                'upload_module' => 'employee_document',
                'company_id' => $companyId,
                'employee_id' => $employee->id,
            ],
        );

        $title = $template->name;
        $sizeBytes = (int) filesize($tempPdfPath);
        $checksum = hash_file('sha256', $tempPdfPath) ?: '';

        return new StoredGeneratedLibraryDocument(
            filePath: $path,
            originalFilename: $filename,
            mimeType: 'application/pdf',
            sizeBytes: $sizeBytes,
            checksum: $checksum,
            documentTypeId: $documentType?->id,
            title: $title,
        );
    }

    /**
     * Create the EmployeeDocument database record.
     * MUST be called inside the main generation database transaction.
     */
    public function createEmployeeDocumentRecord(
        StoredGeneratedLibraryDocument $stored,
        Employee $employee,
        int $companyId,
        ?int $userId = null,
    ): EmployeeDocument {
        return EmployeeDocument::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'document_type_id' => $stored->documentTypeId,
            'type' => 'other',
            'document_type' => $stored->documentTypeId !== null ? (string) $stored->documentTypeId : 'other',
            'title' => $stored->title,
            'file_path' => $stored->filePath,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->sizeBytes,
            'checksum' => $stored->checksum,
            'current_version' => 1,
            'issue_date' => now()->toDateString(),
            'expiry_date' => null,
            'status' => 'valid',
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Convenience method executing store and create in sequence.
     */
    public function handle(
        Employee $employee,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        string $tempPdfPath,
        int $companyId,
        ?int $userId = null,
    ): EmployeeDocument {
        $stored = $this->storeLibraryFile($employee, $template, $version, $tempPdfPath, $companyId);

        return $this->createEmployeeDocumentRecord($stored, $employee, $companyId, $userId);
    }
}
