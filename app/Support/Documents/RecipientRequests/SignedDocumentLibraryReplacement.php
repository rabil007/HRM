<?php

namespace App\Support\Documents\RecipientRequests;

final class SignedDocumentLibraryReplacement
{
    public function __construct(
        public readonly string $newPath,
        public readonly string $oldPath,
        public readonly int $companyId,
    ) {}
}
