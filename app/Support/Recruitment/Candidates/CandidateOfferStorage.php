<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CandidateOfferStorage
{
    public const DISK = 'local';

    public const ALLOWED_MIMES = [
        'pdf', 'doc', 'docx',
        'jpg', 'jpeg', 'png', 'webp',
    ];

    public const MAX_SIZE_KB = 10240;

    /**
     * @return array{
     *     path: string,
     *     original_file_name: string,
     *     mime_type: string,
     *     file_size_bytes: int,
     *     file_checksum: string
     * }
     */
    public function store(
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        UploadedFile $file,
        string $kind,
    ): array {
        $directory = self::directory(
            (int) $candidate->company_id,
            (int) $candidate->id,
            (int) $offer->id,
        );

        $path = UploadedFileStorage::store(
            $file,
            $directory,
            [
                'disk' => self::DISK,
                'log_context' => [
                    'company_id' => (int) $candidate->company_id,
                    'candidate_id' => (int) $candidate->id,
                    'offer_id' => (int) $offer->id,
                    'upload_module' => 'recruitment_candidate_offer_'.$kind,
                ],
            ],
        );

        try {
            $realPath = $file->getRealPath();

            if (! is_string($realPath)) {
                throw new RuntimeException('The uploaded offer document could not be read.');
            }

            $checksum = hash_file('sha256', $realPath);

            if (! is_string($checksum) || $checksum === '') {
                throw new RuntimeException('The offer document checksum could not be calculated.');
            }

            return [
                'path' => $path,
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

    public function deleteStored(?string $path, int $companyId, int $candidateId, int $offerId): void
    {
        $validated = self::validatedRelativePath($path, $companyId, $candidateId, $offerId);

        if ($validated === null) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($validated);
        } catch (Throwable $exception) {
            Log::warning('Failed to delete a recruitment candidate offer document.', [
                'company_id' => $companyId,
                'candidate_id' => $candidateId,
                'offer_id' => $offerId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function validatedRelativePath(
        ?string $path,
        int $companyId,
        int $candidateId,
        int $offerId,
    ): ?string {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        $prefix = self::directory($companyId, $candidateId, $offerId).'/';

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

    public static function directory(int $companyId, int $candidateId, int $offerId): string
    {
        return sprintf('recruitment/candidates/%d/%d/offers/%d', $companyId, $candidateId, $offerId);
    }
}
