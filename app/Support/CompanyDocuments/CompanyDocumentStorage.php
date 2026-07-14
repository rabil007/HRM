<?php

namespace App\Support\CompanyDocuments;

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentVersion;
use App\Models\DocumentType;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CompanyDocumentStorage
{
    /** @param array<string, mixed> $data */
    public function create(Company $company, DocumentType $documentType, UploadedFile $file, array $data, ?int $userId): CompanyDocument
    {
        $path = $this->store($file, $company);

        try {
            return CompanyDocument::query()->create([
                'company_id' => $company->id,
                'document_type_id' => $documentType->id,
                'title' => filled($data['title'] ?? null) ? $data['title'] : $documentType->title,
                'document_number' => $data['document_number'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                ...$this->fileMetadata($file, $path),
                'current_version' => 1,
                'uploaded_by' => $userId,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    /**
     * @param  list<array{document_type: DocumentType, file: UploadedFile, data: array<string, mixed>}>  $documents
     * @return list<CompanyDocument>
     */
    public function createMany(Company $company, array $documents, ?int $userId): array
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($company, $documents, $userId, &$storedPaths): array {
                $created = [];

                foreach ($documents as $document) {
                    $createdDocument = $this->create(
                        $company,
                        $document['document_type'],
                        $document['file'],
                        $document['data'],
                        $userId,
                    );
                    $storedPaths[] = $createdDocument->file_path;
                    $created[] = $createdDocument;
                }

                return $created;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }
    }

    public function replace(CompanyDocument $document, UploadedFile $file, ?int $userId): CompanyDocument
    {
        $path = $this->store($file, $document->company);

        try {
            return DB::transaction(function () use ($document, $file, $path, $userId): CompanyDocument {
                $lockedDocument = CompanyDocument::query()->lockForUpdate()->findOrFail($document->id);

                CompanyDocumentVersion::query()->create([
                    'company_document_id' => $lockedDocument->id,
                    'company_id' => $lockedDocument->company_id,
                    'version' => $lockedDocument->current_version,
                    'file_path' => $lockedDocument->file_path,
                    'original_filename' => $lockedDocument->original_filename,
                    'mime_type' => $lockedDocument->mime_type,
                    'size_bytes' => $lockedDocument->size_bytes,
                    'checksum' => $lockedDocument->checksum,
                    'replaced_by' => $userId,
                ]);

                $lockedDocument->update([
                    ...$this->fileMetadata($file, $path),
                    'current_version' => $lockedDocument->current_version + 1,
                    'replaced_at' => now(),
                    'replaced_by' => $userId,
                ]);

                return $lockedDocument->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    public function delete(CompanyDocument $document): void
    {
        $document->loadMissing('versions:id,company_document_id,file_path');
        $paths = $document->versions->pluck('file_path')->push($document->file_path)->filter()->all();

        DB::transaction(fn () => $document->delete());
        Storage::disk('local')->delete($paths);
    }

    private function store(UploadedFile $file, Company $company): string
    {
        return UploadedFileStorage::store(
            $file,
            "company-documents/{$company->id}",
            [
                'disk' => 'local',
                'log_context' => ['company_id' => $company->id, 'feature' => 'company_documents'],
            ],
        );
    }

    /** @return array{file_path: string, original_filename: string, mime_type: string, size_bytes: int, checksum: string} */
    private function fileMetadata(UploadedFile $file, string $path): array
    {
        $realPath = $file->getRealPath();

        if (! is_string($realPath)) {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        return [
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $realPath),
        ];
    }
}
