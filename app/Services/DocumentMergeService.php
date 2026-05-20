<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentMergeService
{
    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    public function streamMergedPdf(Collection $documents, Employee $employee): StreamedResponse
    {
        $this->assertMergeable($documents);

        $tempPath = $this->buildMergedPdf($documents);
        $downloadName = $this->mergedDownloadName($employee);

        return response()->streamDownload(function () use ($tempPath): void {
            $handle = fopen($tempPath, 'rb');

            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }

            @unlink($tempPath);
        }, $downloadName, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    public function buildMergedPdf(Collection $documents): string
    {
        $this->assertMergeable($documents);

        $pdf = new Fpdi;

        foreach ($documents as $document) {
            $sourcePath = $this->resolveReadablePath($document);

            abort_if($sourcePath === null, 404, 'One or more selected files could not be read.');

            $pageCount = $pdf->setSourceFile($sourcePath);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $templateId = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'merged_pdf_');

        abort_if($tempPath === false, 500, 'Could not prepare merged PDF.');

        $pdf->Output('F', $tempPath);

        return $tempPath;
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function assertMergeable(Collection $documents): void
    {
        if ($documents->count() < 2) {
            throw ValidationException::withMessages([
                'document_ids' => 'Select at least 2 PDF files to merge.',
            ]);
        }

        $nonPdf = $documents->first(
            fn (EmployeeDocument $document) => $document->mime_type !== 'application/pdf',
        );

        if ($nonPdf !== null) {
            throw ValidationException::withMessages([
                'document_ids' => 'All selected files must be PDF documents.',
            ]);
        }

        $external = $documents->first(
            fn (EmployeeDocument $document) => $this->isExternalUrl((string) $document->file_path),
        );

        if ($external !== null) {
            throw ValidationException::withMessages([
                'document_ids' => 'All selected files must be PDF documents.',
            ]);
        }
    }

    private function resolveReadablePath(EmployeeDocument $document): ?string
    {
        $diskPath = $this->validatedDiskPath((string) $document->file_path, (int) $document->company_id);

        if ($diskPath === null || ! Storage::disk('public')->exists($diskPath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($diskPath);

        return is_readable($absolutePath) ? $absolutePath : null;
    }

    private function mergedDownloadName(Employee $employee): string
    {
        $name = strtoupper($this->sanitizeSegment((string) $employee->name, 'EMPLOYEE'));

        return "{$name}_DOCUMENTS_".now()->format('Ymd').'.pdf';
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
