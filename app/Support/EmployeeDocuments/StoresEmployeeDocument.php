<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoresEmployeeDocument
{
    public function __construct(private DocumentUploadOptimizer $optimizer) {}

    public function create(Employee $employee, DocumentType $documentType, UploadedFile $file, array $data, int $companyId, ?int $userId): EmployeeDocument
    {
        $prepared = $this->optimizer->prepare($file);

        try {
            $path = $this->storeFile($prepared->file, $companyId, $employee->id, $this->storageFolderSegment($documentType));

            return EmployeeDocument::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'document_type_id' => $documentType->id,
                'type' => 'other',
                'document_type' => (string) $documentType->id,
                'title' => $data['title'] ?? $documentType->title,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $prepared->file->getMimeType(),
                'size_bytes' => $prepared->file->getSize(),
                'checksum' => hash_file('sha256', $prepared->file->getRealPath() ?: ''),
                'current_version' => 1,
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => DocumentExpiry::persistedStatus($data['expiry_date'] ?? null),
                'uploaded_by' => $userId,
            ]);
        } finally {
            $prepared->cleanup();
        }
    }

    public function replace(EmployeeDocument $document, UploadedFile $file, int $companyId, int $employeeId, ?int $userId): EmployeeDocument
    {
        $prepared = $this->optimizer->prepare($file);

        try {
            return DB::transaction(function () use ($document, $prepared, $file, $companyId, $employeeId, $userId) {
                EmployeeDocumentVersion::query()->create([
                    'employee_document_id' => $document->id,
                    'company_id' => $document->company_id,
                    'employee_id' => $document->employee_id,
                    'version' => $document->current_version,
                    'file_path' => $document->file_path,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                    'checksum' => $document->checksum,
                    'replaced_by' => $userId,
                ]);

                $document->loadMissing('documentType');

                $segment = $document->documentType instanceof DocumentType
                    ? $this->storageFolderSegment($document->documentType)
                    : Str::slug((string) ($document->document_type ?? 'document'));

                $path = $this->storeFile($prepared->file, $companyId, $employeeId, $segment);

                $document->update([
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $prepared->file->getMimeType(),
                    'size_bytes' => $prepared->file->getSize(),
                    'checksum' => hash_file('sha256', $prepared->file->getRealPath() ?: ''),
                    'current_version' => ((int) $document->current_version) + 1,
                    'replaced_at' => now(),
                ]);

                return $document->refresh();
            });
        } finally {
            $prepared->cleanup();
        }
    }

    private function storageFolderSegment(DocumentType $documentType): string
    {
        return 'type-'.$documentType->id.'-'.Str::slug(Str::limit($documentType->title, 40, ''));
    }

    private function storeFile(UploadedFile $file, int $companyId, int $employeeId, string $folderSegment): string
    {
        return $file->storePublicly(
            "employee-documents/{$companyId}/{$employeeId}/".Str::slug($folderSegment),
            ['disk' => 'public'],
        );
    }
}
