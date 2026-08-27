<?php

namespace App\Support\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Throwable;

final class DocumentTemplatePdfValidator
{
    public const MAX_SIZE_BYTES = 20971520; // 20 MB

    /**
     * @return array{
     *     original_name: string,
     *     size_bytes: int,
     *     page_count: int
     * }
     *
     * @throws ValidationException
     */
    public static function validateAndInspect(UploadedFile $file, string $field = 'file'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $field => 'The uploaded file is not valid.',
            ]);
        }

        // Check file extension
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== 'pdf') {
            throw ValidationException::withMessages([
                $field => 'The uploaded file must have a .pdf extension.',
            ]);
        }

        // Check file size
        $sizeBytes = (int) $file->getSize();
        if ($sizeBytes > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                $field => 'The template PDF size must not exceed 20 MB.',
            ]);
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! file_exists($realPath)) {
            throw ValidationException::withMessages([
                $field => 'The uploaded PDF file could not be read.',
            ]);
        }

        // Check MIME type using finfo
        $finfoMime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        if ($finfoMime !== 'application/pdf') {
            throw ValidationException::withMessages([
                $field => 'The file must be a valid PDF document.',
            ]);
        }

        // Check PDF header signature (%PDF-)
        $handle = @fopen($realPath, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                $field => 'The uploaded PDF file could not be opened for inspection.',
            ]);
        }

        $header = fread($handle, 1024);
        fclose($handle);

        if (! is_string($header) || ! str_contains($header, '%PDF-')) {
            throw ValidationException::withMessages([
                $field => 'The uploaded file does not appear to be a valid PDF.',
            ]);
        }

        // Validate parseability and extract page count using FPDI
        try {
            $fpdi = new Fpdi;
            $pageCount = $fpdi->setSourceFile($realPath);

            if ($pageCount < 1) {
                throw new PdfParserException('The PDF contains no pages.');
            }
        } catch (CrossReferenceException|PdfParserException|FpdiException $e) {
            throw ValidationException::withMessages([
                $field => 'Unable to read the PDF. The file may be corrupt, damaged, or password-protected.',
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                $field => 'The uploaded PDF could not be processed: '.$e->getMessage(),
            ]);
        }

        return [
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $sizeBytes,
            'page_count' => $pageCount,
        ];
    }
}
