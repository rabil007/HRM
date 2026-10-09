<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CandidateCvStorage
{
    public const DISK = 'local';

    public const ALLOWED_MIMES = [
        'pdf', 'doc', 'docx',
        'jpg', 'jpeg', 'png', 'webp',
    ];

    public const MAX_SIZE_KB = 10240; // 10MB

    /**
     * @return array{
     *     cv_path: string,
     *     cv_original_file_name: string,
     *     cv_mime_type: string,
     *     cv_file_size_bytes: int,
     *     cv_file_checksum: string
     * }
     */
    public function store(RecruitmentCandidate $candidate, UploadedFile $file): array
    {
        $directory = self::directory((int) $candidate->company_id, (int) $candidate->id);

        $path = UploadedFileStorage::store(
            $file,
            $directory,
            [
                'disk' => self::DISK,
                'log_context' => [
                    'company_id' => (int) $candidate->company_id,
                    'candidate_id' => (int) $candidate->id,
                    'upload_module' => 'recruitment_candidate_cv',
                ],
            ],
        );

        try {
            $realPath = $file->getRealPath();

            if (! is_string($realPath)) {
                throw new RuntimeException('The uploaded candidate CV could not be read.');
            }

            $checksum = hash_file('sha256', $realPath);

            if (! is_string($checksum) || $checksum === '') {
                throw new RuntimeException('The candidate CV checksum could not be calculated.');
            }

            return [
                'cv_path' => $path,
                'cv_original_file_name' => $file->getClientOriginalName(),
                'cv_mime_type' => (string) $file->getMimeType(),
                'cv_file_size_bytes' => (int) $file->getSize(),
                'cv_file_checksum' => $checksum,
            ];
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    public function deleteStored(?string $path, int $companyId, int $candidateId): void
    {
        $validated = self::validatedRelativePath($path, $companyId, $candidateId);

        if ($validated === null) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($validated);
        } catch (Throwable $exception) {
            Log::warning('Failed to delete a recruitment candidate CV.', [
                'company_id' => $companyId,
                'candidate_id' => $candidateId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function validatedRelativePath(?string $path, int $companyId, int $candidateId): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        $prefix = self::directory($companyId, $candidateId).'/';

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

    public static function directory(int $companyId, int $candidateId): string
    {
        return sprintf('recruitment/candidates/%d/%d', $companyId, $candidateId);
    }
}
