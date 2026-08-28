<?php

namespace App\Support\Documents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class DocumentInstanceStorage
{
    public const DISK = 'local';

    public static function directory(int $companyId): string
    {
        return "document-instances/{$companyId}";
    }

    /**
     * @return array{path: string, checksum: string, size_bytes: int}
     */
    public static function storePdf(string $tempFilePath, int $companyId): array
    {
        if (! file_exists($tempFilePath) || ! is_readable($tempFilePath)) {
            throw new RuntimeException('Temporary PDF file cannot be read for canonical artifact storage.');
        }

        $sizeBytes = filesize($tempFilePath);
        if ($sizeBytes === false || $sizeBytes <= 0) {
            throw new RuntimeException('Temporary PDF file is empty or invalid.');
        }

        $checksum = hash_file('sha256', $tempFilePath);
        if (! is_string($checksum) || $checksum === '') {
            throw new RuntimeException('Failed to calculate checksum for canonical artifact.');
        }

        $directory = self::directory($companyId);
        $filename = (string) Str::uuid().'.pdf';
        $targetPath = "{$directory}/{$filename}";

        $stream = fopen($tempFilePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open temporary PDF file stream.');
        }

        try {
            $stored = Storage::disk(self::DISK)->put($targetPath, $stream);
            if (! $stored) {
                throw new RuntimeException('Failed to store canonical document instance artifact.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $targetPath,
            'checksum' => $checksum,
            'size_bytes' => $sizeBytes,
        ];
    }

    public static function deletePdf(?string $path, int $companyId): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        $expectedPrefix = self::directory($companyId).'/';
        if (! str_starts_with($path, $expectedPrefix)) {
            Log::warning('Rejected delete of document instance PDF path outside company boundary', [
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
            Log::error('Failed to delete document instance PDF', [
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
}
