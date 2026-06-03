<?php

namespace App\Services;

use App\Support\Pdf\Ghostscript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class DocumentPdfCompressionService
{
    public function compress(string $sourcePath): ?string
    {
        if (! (bool) config('services.documents.pdf_compression_enabled', true)) {
            return null;
        }

        if (! is_readable($sourcePath)) {
            Log::warning('PDF compression skipped: source file is not readable.', [
                'path' => $sourcePath,
            ]);

            return null;
        }

        $originalSize = filesize($sourcePath);
        $minBytes = (int) config('services.documents.pdf_compress_min_bytes', 5 * 1024 * 1024);

        if ($originalSize === false || $originalSize <= $minBytes) {
            return null;
        }

        if (! Ghostscript::available()) {
            Log::warning('PDF compression skipped: Ghostscript is not available.', [
                'path' => $sourcePath,
                'size_bytes' => $originalSize,
                'candidates' => Ghostscript::candidateBinaries(),
            ]);

            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdf_compress_');

        if ($tempPath === false) {
            return null;
        }

        $binary = Ghostscript::binary();
        $pdfSetting = (string) config('services.documents.pdf_compression_setting', '/ebook');

        try {
            $result = Process::timeout(120)->run([
                $binary,
                '-dBATCH',
                '-dNOPAUSE',
                '-q',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS='.$pdfSetting,
                '-sOutputFile='.$tempPath,
                $sourcePath,
            ]);

            if ($result->failed() || ! is_readable($tempPath)) {
                Log::warning('PDF compression failed.', [
                    'path' => $sourcePath,
                    'binary' => $binary,
                    'stderr' => $result->errorOutput(),
                ]);

                @unlink($tempPath);

                return null;
            }

            $compressedSize = filesize($tempPath);

            if ($compressedSize === false || $compressedSize >= $originalSize) {
                Log::info('PDF compression skipped: output was not smaller than the original.', [
                    'path' => $sourcePath,
                    'original_size_bytes' => $originalSize,
                    'compressed_size_bytes' => $compressedSize,
                ]);

                @unlink($tempPath);

                return null;
            }

            Log::info('PDF compression succeeded.', [
                'path' => $sourcePath,
                'original_size_bytes' => $originalSize,
                'compressed_size_bytes' => $compressedSize,
                'binary' => $binary,
            ]);

            return $tempPath;
        } catch (Throwable $exception) {
            Log::warning('PDF compression failed with exception.', [
                'path' => $sourcePath,
                'message' => $exception->getMessage(),
            ]);

            @unlink($tempPath);

            return null;
        }
    }
}
