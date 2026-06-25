<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Support\Uploads\UploadedFileStorage;
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

    public function replace(EmployeeDocument $document, UploadedFile $file, int $companyId, int $employeeId, ?int $userId, array $data = []): EmployeeDocument
    {
        $prepared = $this->optimizer->prepare($file);

        try {
            return DB::transaction(function () use ($document, $prepared, $file, $companyId, $employeeId, $userId, $data) {
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

                $expiryDate = array_key_exists('expiry_date', $data)
                    ? ($data['expiry_date'] ?? null)
                    : $document->expiry_date?->toDateString();

                $document->update([
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $prepared->file->getMimeType(),
                    'size_bytes' => $prepared->file->getSize(),
                    'checksum' => hash_file('sha256', $prepared->file->getRealPath() ?: ''),
                    'current_version' => ((int) $document->current_version) + 1,
                    'replaced_at' => now(),
                    'document_number' => array_key_exists('document_number', $data)
                        ? ($data['document_number'] ?? null)
                        : $document->document_number,
                    'issue_date' => array_key_exists('issue_date', $data)
                        ? ($data['issue_date'] ?? null)
                        : $document->issue_date?->toDateString(),
                    'expiry_date' => $expiryDate,
                    'status' => DocumentExpiry::persistedStatus($expiryDate),
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
        return UploadedFileStorage::storePublicly(
            $file,
            "employee-documents/{$companyId}/{$employeeId}/".Str::slug($folderSegment),
            ['disk' => 'public'],
        );
    }
}
