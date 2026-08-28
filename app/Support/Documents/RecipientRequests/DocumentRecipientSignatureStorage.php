<?php

namespace App\Support\Documents\RecipientRequests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientSignatureStorage
{
    public const DISK = 'local';

    public static function storeFromDataUri(string $dataUri, int $companyId, int $requestId): string
    {
        [$binary, $extension] = self::decodeDataUri($dataUri);

        $directory = "document-recipient-signatures/{$companyId}/{$requestId}";
        $filename = (string) Str::uuid().'.'.$extension;
        $path = "{$directory}/{$filename}";

        $stored = Storage::disk(self::DISK)->put($path, $binary);

        if (! $stored) {
            throw ValidationException::withMessages([
                'signature_data' => 'Could not store signature image.',
            ]);
        }

        return $path;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function decodeDataUri(string $signatureData): array
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $signatureData, $matches)) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signatureData) ?: '', true);

        if ($binary === false || strlen($binary) < 50 || strlen($binary) > 2_000_000) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        $imageInfo = @getimagesizefromstring($binary);

        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        [$width, $height] = $imageInfo;

        if ($width > 4000 || $height > 4000) {
            throw ValidationException::withMessages([
                'signature_data' => 'Signature image dimensions are too large.',
            ]);
        }

        $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : 'png';

        return [$binary, $extension];
    }

    public static function delete(?string $path, int $companyId): void
    {
        $path = self::validatedRelativePath($path, $companyId);

        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public static function validatedRelativePath(?string $relativePath, int $companyId): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }

        $path = str_replace('\\', '/', trim($relativePath));

        if (str_starts_with($path, '/') || str_contains($path, '..')) {
            return null;
        }

        $prefix = "document-recipient-signatures/{$companyId}/";

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        return $path;
    }
}
