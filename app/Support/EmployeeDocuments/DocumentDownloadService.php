<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DocumentDownloadService
{
    public function __construct(
        private DocumentBulkActionService $bulkActions,
    ) {}

    public function assertEmployeeAccessible(Employee $employee, int $companyId): void
    {
        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
    }

    public function assertDocumentAccessible(EmployeeDocument $document, int $companyId): void
    {
        DocumentAccess::assertDocumentInCompany($document, $companyId);
    }

    public function employeeZipDownloadName(Employee $employee): string
    {
        $name = strtoupper($this->sanitizeSegment((string) $employee->name, 'EMPLOYEE'));
        $number = $this->sanitizeSegment((string) $employee->employee_no, 'UNKNOWN');

        return "{$name}_{$number}_documents.zip";
    }

    public function employeeArchiveFolderName(Employee $employee): string
    {
        return $this->sanitizeFilename((string) ($employee->name ?: "employee-{$employee->id}"));
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    public function documentsForEmployeeArchive(Employee $employee, int $companyId): Collection
    {
        return EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'company_id',
                'employee_id',
                'file_path',
                'original_filename',
                'mime_type',
                'title',
                'document_type',
            ]);
    }

    public function streamEmployeeDocumentsZip(Employee $employee, int $companyId): StreamedResponse
    {
        $documents = $this->documentsForEmployeeArchive($employee, $companyId);

        abort_if($documents->isEmpty(), 404, 'No documents found for this employee.');

        return $this->buildAndStreamZip(
            function (ZipArchive $zip) use ($documents, $companyId): int {
                $usedNames = [];

                return $this->addDocumentsToArchive($zip, $documents, $companyId, $usedNames);
            },
            $this->employeeZipDownloadName($employee),
        );
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public function streamBulkEmployeesZip(array $employeeIds, int $companyId): StreamedResponse
    {
        $employees = $this->bulkActions->employeesForDownload($employeeIds, $companyId);

        $downloadName = $employees->count() === 1
            ? $this->employeeZipDownloadName($employees->first())
            : 'documents_export.zip';

        return $this->buildAndStreamZip(
            function (ZipArchive $zip) use ($employees, $companyId): int {
                $added = 0;
                $useSubfolders = $employees->count() > 1;

                foreach ($employees as $employee) {
                    $documents = $this->documentsForEmployeeArchive($employee, $companyId);

                    if ($documents->isEmpty()) {
                        continue;
                    }

                    $usedNames = [];
                    $prefix = $useSubfolders ? $this->employeeArchiveFolderName($employee).'/' : '';

                    $added += $this->addDocumentsToArchive($zip, $documents, $companyId, $usedNames, $prefix);
                }

                return $added;
            },
            $downloadName,
        );
    }

    /**
     * @param  list<int>  $documentIds
     */
    public function streamBulkDocumentsZip(
        array $documentIds,
        int $companyId,
        string $downloadName = 'documents_export.zip',
    ): StreamedResponse {
        $documents = $this->bulkActions->documentsForDownload($documentIds, $companyId);

        abort_if($documents->isEmpty(), 404, 'No documents found.');

        return $this->buildAndStreamZip(
            function (ZipArchive $zip) use ($documents, $companyId): int {
                $usedNames = [];

                return $this->addDocumentsToArchive($zip, $documents, $companyId, $usedNames);
            },
            $downloadName,
        );
    }

    public function downloadSingleDocument(EmployeeDocument $document, int $companyId): Response
    {
        $this->assertDocumentAccessible($document, $companyId);

        $downloadName = $this->downloadFilename($document);

        if ($this->isExternalUrl((string) $document->file_path)) {
            return redirect()->away((string) $document->file_path);
        }

        $diskPath = $this->validatedDiskPath((string) $document->file_path, $companyId);

        abort_if($diskPath === null || ! Storage::disk('public')->exists($diskPath), 404, 'File not found.');

        return Storage::disk('public')->download(
            $diskPath,
            $downloadName,
            ['Content-Type' => (string) ($document->mime_type ?: 'application/octet-stream')],
        );
    }

    /**
     * @param  callable(ZipArchive): int  $buildArchive
     */
    private function buildAndStreamZip(callable $buildArchive, string $downloadName): StreamedResponse
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'employee_docs_');

        abort_if($tmpPath === false, 500, 'Could not prepare download.');

        $zip = new ZipArchive;

        abort_unless($zip->open($tmpPath, ZipArchive::OVERWRITE) === true, 500, 'Could not create archive.');

        $added = $buildArchive($zip);

        $zip->close();

        abort_if($added === 0, 404, 'No downloadable files found.');

        $contentLength = filesize($tmpPath);

        return response()->streamDownload(function () use ($tmpPath): void {
            $handle = fopen($tmpPath, 'rb');

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }

            @unlink($tmpPath);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
            'Content-Length' => $contentLength !== false ? (string) $contentLength : null,
        ]);
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @param  array<string, int>  $usedNames
     */
    private function addDocumentsToArchive(
        ZipArchive $zip,
        Collection $documents,
        int $companyId,
        array &$usedNames,
        string $prefix = '',
    ): int {
        $added = 0;

        foreach ($documents as $document) {
            if ($this->addDocumentToArchive($zip, $document, $companyId, $usedNames, $prefix)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * @param  array<string, int>  $usedNames
     */
    private function addDocumentToArchive(
        ZipArchive $zip,
        EmployeeDocument $document,
        int $companyId,
        array &$usedNames,
        string $prefix = '',
    ): bool {
        $entryName = $prefix.$this->uniqueArchiveEntryName($document, $usedNames);

        if ($this->isExternalUrl((string) $document->file_path)) {
            $contents = @file_get_contents((string) $document->file_path);

            if ($contents === false) {
                return false;
            }

            return $zip->addFromString($entryName, $contents);
        }

        $diskPath = $this->validatedDiskPath((string) $document->file_path, $companyId);

        if ($diskPath === null) {
            return false;
        }

        $absolutePath = Storage::disk('public')->path($diskPath);

        if (is_readable($absolutePath) && $zip->addFile($absolutePath, $entryName)) {
            return true;
        }

        $contents = Storage::disk('public')->get($diskPath);

        return $contents !== null && $zip->addFromString($entryName, $contents);
    }

    /**
     * @param  array<string, int>  $usedNames
     */
    private function uniqueArchiveEntryName(EmployeeDocument $document, array &$usedNames): string
    {
        $entryName = $this->downloadFilename($document);

        if (! isset($usedNames[$entryName])) {
            $usedNames[$entryName] = 1;

            return $entryName;
        }

        $usedNames[$entryName]++;
        $pathInfo = pathinfo($entryName);
        $basename = $pathInfo['filename'] ?? 'document';
        $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';

        return "{$basename}_{$usedNames[$entryName]}{$extension}";
    }

    private function downloadFilename(EmployeeDocument $document): string
    {
        $candidate = (string) ($document->original_filename ?: $document->title ?: "document-{$document->id}");

        return $this->sanitizeFilename($candidate);
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename($filename);
        $basename = preg_replace('/[^\w\.\-]+/u', '_', $basename) ?? 'document';
        $basename = trim($basename, '._');

        return $basename !== '' ? $basename : 'document';
    }

    private function sanitizeSegment(string $value, string $fallback): string
    {
        $segment = preg_replace('/[^a-zA-Z0-9\-]+/', '-', trim($value)) ?? '';
        $segment = trim($segment, '-');

        return $segment !== '' ? $segment : $fallback;
    }

    private function isExternalUrl(string $filePath): bool
    {
        return str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://');
    }

    private function validatedDiskPath(string $filePath, int $companyId): ?string
    {
        $filePath = ltrim($filePath, '/');

        if ($filePath === '' || str_contains($filePath, '..')) {
            return null;
        }

        $expectedPrefix = "employee-documents/{$companyId}/";

        if (! str_starts_with($filePath, $expectedPrefix)) {
            return null;
        }

        return $filePath;
    }
}
