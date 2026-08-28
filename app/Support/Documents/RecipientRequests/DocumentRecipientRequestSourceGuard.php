<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\DocumentInstanceStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientRequestSourceGuard
{
    public function assertExactSource(
        DocumentRecipientRequest $request,
        DocumentInstanceVersion $version,
    ): void {
        if (
            (int) $version->id !== (int) $request->source_document_instance_version_id
            || (int) $version->company_id !== (int) $request->company_id
            || (int) $version->document_instance_id !== (int) $request->document_instance_id
        ) {
            throw ValidationException::withMessages([
                'token' => 'The document attached to this request is no longer valid.',
            ]);
        }

        $expectedChecksum = strtolower(trim((string) $request->source_checksum_sha256));
        $versionChecksum = strtolower(trim((string) $version->checksum));

        if (
            strlen($expectedChecksum) !== 64
            || strlen($versionChecksum) !== 64
            || ! hash_equals($expectedChecksum, $versionChecksum)
        ) {
            throw ValidationException::withMessages([
                'token' => 'The document attached to this request failed integrity validation.',
            ]);
        }

        $path = DocumentInstanceStorage::validatedRelativePath(
            $version->file_path,
            (int) $request->company_id,
        );

        if ($path === null || ! Storage::disk(DocumentInstanceStorage::DISK)->exists($path)) {
            throw ValidationException::withMessages([
                'token' => 'The document attached to this request is unavailable.',
            ]);
        }

        $actualChecksum = hash_file(
            'sha256',
            Storage::disk(DocumentInstanceStorage::DISK)->path($path),
        );

        if (! is_string($actualChecksum) || ! hash_equals($expectedChecksum, strtolower($actualChecksum))) {
            throw ValidationException::withMessages([
                'token' => 'The document attached to this request failed integrity validation.',
            ]);
        }
    }
}
