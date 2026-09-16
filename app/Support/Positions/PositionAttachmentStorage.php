<?php

namespace App\Support\Positions;

use App\Models\Position;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class PositionAttachmentStorage
{
    public const DISK = 'local';

    /**
     * @return array{
     *     attachment_path: string,
     *     attachment_original_name: string,
     *     attachment_mime_type: string,
     *     attachment_size_bytes: int,
     *     attachment_checksum: string
     * }
     */
    public function store(Position $position, UploadedFile $file): array
    {
        $path = UploadedFileStorage::store(
            $file,
            self::directory((int) $position->company_id, (int) $position->id),
            [
                'disk' => self::DISK,
                'log_context' => [
                    'company_id' => (int) $position->company_id,
                    'position_id' => (int) $position->id,
                    'upload_module' => 'position_attachment',
                ],
            ],
        );

        try {
            $realPath = $file->getRealPath();

            if (! is_string($realPath)) {
                throw new RuntimeException('The uploaded position attachment could not be read.');
            }

            $checksum = hash_file('sha256', $realPath);

            if (! is_string($checksum) || $checksum === '') {
                throw new RuntimeException('The position attachment checksum could not be calculated.');
            }

            return [
                'attachment_path' => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime_type' => (string) $file->getMimeType(),
                'attachment_size_bytes' => (int) $file->getSize(),
                'attachment_checksum' => $checksum,
            ];
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    public function delete(?string $path, int $companyId, int $positionId): void
    {
        $path = self::validatedRelativePath($path, $companyId, $positionId);

        if ($path !== null) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Failed to delete a position attachment.', [
                    'company_id' => $companyId,
                    'position_id' => $positionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public static function validatedRelativePath(?string $path, int $companyId, int $positionId): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        $prefix = self::directory($companyId, $positionId).'/';

        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, '..')
            || ! str_starts_with($path, $prefix)
        ) {
            return null;
        }

        return $path;
    }

    private static function directory(int $companyId, int $positionId): string
    {
        return "position-attachments/{$companyId}/{$positionId}";
    }
}
