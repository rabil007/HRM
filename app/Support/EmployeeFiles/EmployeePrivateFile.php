<?php

namespace App\Support\EmployeeFiles;

use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class EmployeePrivateFile
{
    public const DISK = 'local';

    public const LEGACY_DISK = 'public';

    /**
     * @param  array<string, mixed>  $logContext
     */
    public static function store(UploadedFile $file, string $directory, array $logContext = []): string
    {
        return UploadedFileStorage::store(
            $file,
            $directory,
            [
                'disk' => self::DISK,
                'log_context' => $logContext,
            ],
        );
    }

    public static function resolve(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): ?ResolvedEmployeeFile {
        $path = self::validatedRelativePath($relativePath, $companyId, $kind);

        if ($path === null) {
            return null;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return new ResolvedEmployeeFile($disk, $path);
            }
        }

        return null;
    }

    public static function deleteStored(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): void {
        $path = self::validatedRelativePath($relativePath, $companyId, $kind);

        if ($path === null) {
            return;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    public static function copyLegacyPublicToPrivate(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): bool {
        $path = self::validatedRelativePath($relativePath, $companyId, $kind);

        if ($path === null) {
            return false;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            return true;
        }

        if (! Storage::disk(self::LEGACY_DISK)->exists($path)) {
            return false;
        }

        $copied = self::copyFromLegacyDisk($path);

        return $copied && Storage::disk(self::DISK)->exists($path);
    }

    public static function deleteLegacyPublicCopy(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): bool {
        $path = self::validatedRelativePath($relativePath, $companyId, $kind);

        if ($path === null) {
            return false;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return false;
        }

        if (! Storage::disk(self::LEGACY_DISK)->exists($path)) {
            return true;
        }

        Storage::disk(self::LEGACY_DISK)->delete($path);

        return ! Storage::disk(self::LEGACY_DISK)->exists($path);
    }

    public static function hasLegacyPublicCopy(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): bool {
        $path = self::validatedRelativePath($relativePath, $companyId, $kind);

        return $path !== null && Storage::disk(self::LEGACY_DISK)->exists($path);
    }

    public static function isRemoteUrl(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    public static function normalizedRelativePath(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        $relativePath = trim($relativePath);

        if ($relativePath === '' || self::isRemoteUrl($relativePath)) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    public static function legacyPublicExists(?string $relativePath): bool
    {
        $path = self::normalizedRelativePath($relativePath);

        return $path !== null && Storage::disk(self::LEGACY_DISK)->exists($path);
    }

    /**
     * @return list<string>
     */
    public static function legacyPublicFilesInPrefix(string $prefix): array
    {
        $normalizedPrefix = self::normalizedRelativePath($prefix);

        if ($normalizedPrefix === null) {
            return [];
        }

        $normalizedPrefix = rtrim($normalizedPrefix, '/').'/';

        if (! self::isControlledLegacyPrefix($normalizedPrefix)) {
            return [];
        }

        $files = Storage::disk(self::LEGACY_DISK)->allFiles(rtrim($normalizedPrefix, '/'));
        $safe = [];

        foreach ($files as $file) {
            $path = self::normalizedRelativePath($file);

            if ($path !== null && str_starts_with($path, $normalizedPrefix)) {
                $safe[] = $path;
            }
        }

        return $safe;
    }

    public static function validatedRelativePath(
        ?string $relativePath,
        int $companyId,
        EmployeePrivateFileKind $kind,
    ): ?string {
        $path = self::normalizedRelativePath($relativePath);

        if ($path === null) {
            return null;
        }

        if (! str_starts_with($path, $kind->directoryPrefix($companyId))) {
            return null;
        }

        return $path;
    }

    private static function isControlledLegacyPrefix(string $prefix): bool
    {
        return (bool) preg_match('#^employee-documents/\d+/$#', $prefix)
            || (bool) preg_match('#^employees/\d+/training-certificates/$#', $prefix);
    }

    private static function copyFromLegacyDisk(string $path): bool
    {
        $stream = Storage::disk(self::LEGACY_DISK)->readStream($path);

        if (is_resource($stream)) {
            try {
                $written = Storage::disk(self::DISK)->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($written) {
                return true;
            }
        }

        $contents = Storage::disk(self::LEGACY_DISK)->get($path);

        if (! is_string($contents)) {
            return false;
        }

        return Storage::disk(self::DISK)->put($path, $contents);
    }
}
