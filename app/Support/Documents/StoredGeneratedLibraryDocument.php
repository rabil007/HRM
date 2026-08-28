<?php

namespace App\Support\Documents;

final readonly class StoredGeneratedLibraryDocument
{
    public function __construct(
        public string $filePath,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
        public string $checksum,
        public ?int $documentTypeId,
        public string $title,
    ) {}
}
