<?php

namespace App\Support\BulkDocuments;

final class EsignPreviewPdfCache
{
    public static function path(string $documentType, bool $showGuides): string
    {
        $suffix = $showGuides ? 'guides' : 'plain';

        return storage_path("app/esign-preview/{$documentType}-{$suffix}.pdf");
    }

    public static function get(string $documentType, bool $showGuides): ?string
    {
        $path = self::path($documentType, $showGuides);

        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    public static function put(string $documentType, bool $showGuides, string $pdf): void
    {
        $path = self::path($documentType, $showGuides);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $pdf);
    }
}
