<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class SyncGeneratedEmployeeDocument
{
    public function handle(
        Employee $employee,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        string $tempPdfPath,
        int $companyId,
        ?int $userId = null,
    ): EmployeeDocument {
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
            true, // test mode allows local CLI/temp file paths
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

        return EmployeeDocument::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'document_type_id' => $documentType?->id,
            'type' => 'other',
            'document_type' => $documentType !== null ? (string) $documentType->id : 'other',
            'title' => $title,
            'file_path' => $path,
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'current_version' => 1,
            'issue_date' => now()->toDateString(),
            'expiry_date' => null,
            'status' => 'valid',
            'uploaded_by' => $userId,
        ]);
    }
}
