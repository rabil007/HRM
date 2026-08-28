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
        $originalPath = $path;
        $path = self::validatedRelativePath($path, $companyId);

        if ($path === null) {
            if ($originalPath !== null && trim($originalPath) !== '') {
                Log::warning('Rejected delete of document instance PDF path outside company boundary', [
                    'company_id' => $companyId,
                ]);
            }

            return;
        }

        try {
            if (Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to delete document instance PDF', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
            ]);
        }
    }

    public static function exists(?string $path, int $companyId): bool
    {
        $path = self::validatedRelativePath($path, $companyId);

        if ($path === null) {
            return false;
        }

        return Storage::disk(self::DISK)->exists($path);
    }

    public static function validatedRelativePath(?string $relativePath, int $companyId): ?string
    {
        $path = self::normalizedRelativePath($relativePath);

        if ($path === null) {
            return null;
        }

        $segments = explode('/', $path);

        if (count($segments) < 3) {
            return null;
        }

        if ($segments[0] !== 'document-instances') {
            return null;
        }

        if ($segments[1] !== (string) $companyId) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    public static function normalizedRelativePath(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        $relativePath = trim($relativePath);

        if ($relativePath === '') {
            return null;
        }

        if (str_starts_with($relativePath, '/') || str_contains($relativePath, '\\')) {
            return null;
        }

        $path = str_replace('\\', '/', $relativePath);

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
