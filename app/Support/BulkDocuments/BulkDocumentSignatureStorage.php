<?php

namespace App\Support\BulkDocuments;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BulkDocumentSignatureStorage
{
    public const DISK = 'local';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public static function put(string $path, string $contents): void
    {
        self::disk()->put($path, $contents);
    }

    public static function exists(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (self::disk()->exists($path)) {
            return true;
        }

        return Storage::disk('public')->exists($path);
    }

    public static function path(string $path): string
    {
        if (self::disk()->exists($path)) {
            return self::disk()->path($path);
        }

        return Storage::disk('public')->path($path);
    }

    public static function download(string $path, string $name, array $headers = []): StreamedResponse
    {
        if (self::disk()->exists($path)) {
            return self::disk()->download($path, $name, $headers);
        }

        return Storage::disk('public')->download($path, $name, $headers);
    }
}
