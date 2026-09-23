<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class RequirementAttachmentStorage
{
    public const DISK = 'local';

    /**
     * Allowed mime types for requirement attachments.
     */
    public const ALLOWED_MIMES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'rtf',
        'jpg', 'jpeg', 'png', 'webp',
    ];

    public const MAX_SIZE_KB = 20480; // 20MB

    /**
     * @return array{
     *     file_path: string,
     *     original_file_name: string,
     *     mime_type: string,
     *     file_size_bytes: int,
     *     file_checksum: string
     * }
     */
    public function store(RecruitmentRequirement $requirement, UploadedFile $file): array
    {
        $directory = self::directory((int) $requirement->company_id, (int) $requirement->id);

        $path = UploadedFileStorage::store(
            $file,
            $directory,
            [
                'disk' => self::DISK,
                'log_context' => [
                    'company_id' => (int) $requirement->company_id,
                    'requirement_id' => (int) $requirement->id,
                    'upload_module' => 'recruitment_requirement_attachment',
                ],
            ],
        );

        try {
            $realPath = $file->getRealPath();

            if (! is_string($realPath)) {
                throw new RuntimeException('The uploaded requirement attachment could not be read.');
            }

            $checksum = hash_file('sha256', $realPath);

            if (! is_string($checksum) || $checksum === '') {
                throw new RuntimeException('The requirement attachment checksum could not be calculated.');
            }

            return [
                'file_path' => $path,
                'original_file_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'file_size_bytes' => (int) $file->getSize(),
                'file_checksum' => $checksum,
            ];
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    public function delete(RecruitmentRequirementAttachment $attachment): void
    {
        $path = self::validatedRelativePath(
            $attachment->file_path,
            (int) $attachment->company_id,
            (int) $attachment->recruitment_requirement_id,
        );

        if ($path !== null) {
            try {
                Storage::disk(self::DISK)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Failed to delete a recruitment requirement attachment.', [
                    'company_id' => $attachment->company_id,
                    'requirement_id' => $attachment->recruitment_requirement_id,
                    'attachment_id' => $attachment->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public static function validatedRelativePath(?string $path, int $companyId, int $requirementId): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        $prefix = self::directory($companyId, $requirementId).'/';

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

    public static function directory(int $companyId, int $requirementId): string
    {
        return sprintf('recruitment/requirements/%d/%d', $companyId, $requirementId);
    }
}
