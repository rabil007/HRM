<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

        $sourcePaths = $this->resolveSourcePaths($documents);

        try {
            return $this->mergeWithFpdi($documents, $sourcePaths);
        } catch (CrossReferenceException|PdfParserException|FpdiException $exception) {
            if ($this->ghostscriptAvailable()) {
                return $this->mergeWithGhostscript($sourcePaths);
            }

            throw ValidationException::withMessages([
                'document_ids' => $this->unsupportedPdfMessage($exception),
            ]);
        }
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @param  list<string>  $sourcePaths
     */
    private function mergeWithFpdi(Collection $documents, array $sourcePaths): string
    {
        $pdf = new Fpdi;

        foreach ($documents as $index => $document) {
            try {
                $pageCount = $pdf->setSourceFile($sourcePaths[$index]);

                for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                    $templateId = $pdf->importPage($pageNumber);
                    $size = $pdf->getTemplateSize($templateId);

                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                }
            } catch (CrossReferenceException|PdfParserException|FpdiException $exception) {
                $label = $document->original_filename
                    ?? $document->title
                    ?? "document-{$document->id}";

                throw new PdfParserException(
                    "Unable to read \"{$label}\": ".$exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'merged_pdf_');

        abort_if($tempPath === false, 500, 'Could not prepare merged PDF.');

        $pdf->Output('F', $tempPath);

        return $tempPath;
    }

    /**
     * @param  list<string>  $sourcePaths
     */
    private function mergeWithGhostscript(array $sourcePaths): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'merged_pdf_');

        abort_if($tempPath === false, 500, 'Could not prepare merged PDF.');

        $binary = (string) config('services.pdf.ghostscript_binary', 'gs');

        $result = Process::timeout(120)->run([
            $binary,
            '-dBATCH',
            '-dNOPAUSE',
            '-q',
            '-sDEVICE=pdfwrite',
            '-sOutputFile='.$tempPath,
            ...$sourcePaths,
        ]);

        if ($result->failed() || ! is_readable($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);

            throw ValidationException::withMessages([
                'document_ids' => 'Unable to merge the selected PDF files. Try re-saving them or use bulk download instead.',
            ]);
        }

        return $tempPath;
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<string>
     */
    private function resolveSourcePaths(Collection $documents): array
    {
        $paths = [];

        foreach ($documents as $document) {
            $sourcePath = $this->resolveReadablePath($document);

            abort_if($sourcePath === null, 404, 'One or more selected files could not be read.');

            $paths[] = $sourcePath;
        }

        return $paths;
    }

    private function ghostscriptAvailable(): bool
    {
        $binary = (string) config('services.pdf.ghostscript_binary', 'gs');

        try {
            return Process::run([$binary, '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function unsupportedPdfMessage(Throwable $exception): string
    {
        if (str_contains($exception->getMessage(), 'Unable to read "')) {
            return $exception->getMessage().' This PDF may use advanced compression. Install Ghostscript on the server or re-save the file as a standard PDF.';
        }

        return 'One or more selected PDFs use advanced compression that cannot be merged. Install Ghostscript on the server, re-save the files as standard PDFs, or use bulk download instead.';
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
