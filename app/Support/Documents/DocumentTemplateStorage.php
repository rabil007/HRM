<?php

namespace App\Support\Documents;

use App\Models\DocumentGenerationTemplateVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class DocumentTemplateStorage
{
    public const DISK = 'local';

    public static function directory(int $companyId): string
    {
        return "document-generation-templates/{$companyId}";
    }

    public static function storePdf(UploadedFile $file, int $companyId): string
    {
        $directory = self::directory($companyId);
        $filename = (string) Str::uuid().'.pdf';

        $storedPath = Storage::disk(self::DISK)->putFileAs($directory, $file, $filename);

        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Failed to store private template PDF.');
        }

        return $storedPath;
    }

    public static function copyPdf(string $sourcePath, int $companyId): string
    {
        $expectedPrefix = self::directory($companyId).'/';
        if (! str_starts_with($sourcePath, $expectedPrefix)) {
            throw new \InvalidArgumentException('Source template PDF path is outside company boundary.');
        }

        if (! Storage::disk(self::DISK)->exists($sourcePath)) {
            throw new \RuntimeException('Source template PDF does not exist for copying.');
        }

        $targetDirectory = self::directory($companyId);
        $targetPath = "{$targetDirectory}/".(string) Str::uuid().'.pdf';

        $copied = Storage::disk(self::DISK)->copy($sourcePath, $targetPath);

        if (! $copied) {
            throw new \RuntimeException('Failed to copy private template PDF.');
        }

        return $targetPath;
    }

    public static function deletePdf(?string $path, int $companyId): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        $expectedPrefix = self::directory($companyId).'/';
        if (! str_starts_with($path, $expectedPrefix)) {
            Log::warning('Rejected delete of PDF path outside company boundary', [
                'path' => $path,
                'company_id' => $companyId,
            ]);

            return;
        }

        try {
            if (Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to delete private template PDF', [
                'error' => $e->getMessage(),
                'path' => $path,
                'company_id' => $companyId,
            ]);
        }
    }

    public static function exists(?string $path, int $companyId): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }

        $expectedPrefix = self::directory($companyId).'/';
        if (! str_starts_with($path, $expectedPrefix)) {
            return false;
        }

        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Resolve a safe absolute filesystem path for a stored template PDF.
     *
     * Verifies the path belongs to the given company's directory boundary and
     * that the file physically exists on disk before returning the absolute path.
     * For use by renderers that need to pass a real file path to FPDI.
     *
     * @throws \RuntimeException if the path is outside the company boundary or the file is missing.
     */
    public static function absolutePath(string $storagePath, int $companyId): string
    {
        $expectedPrefix = self::directory($companyId).'/';

        if (! str_starts_with($storagePath, $expectedPrefix)) {
            throw new \RuntimeException('Template source PDF is outside the company storage boundary.');
        }

        if (! Storage::disk(self::DISK)->exists($storagePath)) {
            throw new \RuntimeException('Template source PDF file is not available.');
        }

        return Storage::disk(self::DISK)->path($storagePath);
    }

    public static function response(DocumentGenerationTemplateVersion $version, int $companyId): Response
    {
        $path = (string) $version->source_pdf_path;

        abort_unless(self::exists($path, $companyId), 404, 'Template PDF not found.');

        $filename = $version->source_pdf_original_name ?: "template-{$version->document_generation_template_id}-v{$version->version}.pdf";

        return Storage::disk(self::DISK)->response($path, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
        ]);
    }
}
