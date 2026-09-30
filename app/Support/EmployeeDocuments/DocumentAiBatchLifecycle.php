<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Enums\DocumentAiErrorCode;
use App\Models\DocumentAiBatch;
use App\Models\DocumentAiBatchItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DocumentAiBatchLifecycle
{
    /** Private temp files and normalized batch results expire after this window. */
    public const TEMP_RETENTION_HOURS = 24;

    /**
     * Job timeout is 45s; grace covers scheduling, disk IO, and worker reclaim lag.
     * Expired batches with processing older than this window are treated as abandoned.
     */
    public const JOB_TIMEOUT_SECONDS = 45;

    public const PROCESSING_GRACE_SECONDS = 120;

    /**
     * Protects in-flight batch creation: uncommitted DB rows are invisible to
     * cleanup, so very recent orphan-looking directories must not be deleted yet.
     */
    public const ORPHAN_DIRECTORY_GRACE_SECONDS = 900;

    public static function staleProcessingThresholdSeconds(): int
    {
        return self::JOB_TIMEOUT_SECONDS + self::PROCESSING_GRACE_SECONDS;
    }

    public static function refresh(DocumentAiBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            /** @var DocumentAiBatch|null $locked */
            $locked = DocumentAiBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if (in_array($locked->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
                return;
            }

            $counts = $locked->items()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $completed = (int) ($counts[DocumentAiBatchItemStatus::Completed->value] ?? 0);
            $failed = (int) ($counts[DocumentAiBatchItemStatus::Failed->value] ?? 0);
            $active = (int) ($counts[DocumentAiBatchItemStatus::Queued->value] ?? 0)
                + (int) ($counts[DocumentAiBatchItemStatus::Processing->value] ?? 0);

            $status = $active > 0
                ? DocumentAiBatchStatus::Processing
                : ($failed > 0 ? DocumentAiBatchStatus::CompletedWithErrors : DocumentAiBatchStatus::Completed);

            $locked->update([
                'status' => $status,
                'completed_items' => $completed,
                'failed_items' => $failed,
            ]);
        });

        $batch->refresh();
    }

    public static function isRecentProcessing(DocumentAiBatchItem $item): bool
    {
        if ($item->status !== DocumentAiBatchItemStatus::Processing) {
            return false;
        }

        $startedAt = $item->started_at ?? $item->updated_at;
        if ($startedAt === null) {
            return false;
        }

        return $startedAt->gt(now()->subSeconds(self::staleProcessingThresholdSeconds()));
    }

    public static function isStaleProcessing(DocumentAiBatchItem $item): bool
    {
        return $item->status === DocumentAiBatchItemStatus::Processing
            && ! self::isRecentProcessing($item);
    }

    public static function terminalizeStaleProcessing(DocumentAiBatch $batch): void
    {
        $batch->loadMissing('items');

        foreach ($batch->items as $item) {
            if (! self::isStaleProcessing($item)) {
                continue;
            }

            $item->update([
                'status' => DocumentAiBatchItemStatus::Failed,
                'safe_error_code' => DocumentAiErrorCode::ExtractionFailed->value,
                'completed_at' => now(),
            ]);
        }
    }

    public static function deleteTemporaryFiles(DocumentAiBatch $batch): void
    {
        $disk = Storage::disk('local');
        $batch->loadMissing('items');

        foreach ($batch->items as $item) {
            self::deleteTemporaryPath((string) $item->temporary_file_reference, $batch);
        }

        $directory = 'document-ai-temp/'.$batch->company_id.'/'.$batch->id;
        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }
    }

    public static function deleteTemporaryPath(string $path, ?DocumentAiBatch $batch = null): void
    {
        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, 'document-ai-temp/')) {
            return;
        }

        if ($batch !== null && ! self::isOwnedTemporaryPath($batch, $path)) {
            return;
        }

        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    public static function isOwnedTemporaryPath(DocumentAiBatch $batch, string $path): bool
    {
        $prefix = 'document-ai-temp/'.$batch->company_id.'/'.$batch->id.'/';

        return str_starts_with($path, $prefix) && ! str_contains($path, '..');
    }

    public static function purge(DocumentAiBatch $batch): void
    {
        self::deleteTemporaryFiles($batch);
        $batch->delete();
    }

    /**
     * Remove orphaned private temp directories left behind by DB cascade deletes.
     * Only touches paths under document-ai-temp/{companyId}/{batchId}/.
     * Recent directories without a visible batch row are retained for
     * ORPHAN_DIRECTORY_GRACE_SECONDS so in-flight creation is not raced.
     */
    public static function purgeOrphanTemporaryDirectories(): int
    {
        $disk = Storage::disk('local');
        $root = 'document-ai-temp';
        if (! $disk->exists($root)) {
            return 0;
        }

        $removed = 0;

        foreach ($disk->directories($root) as $companyDirectory) {
            $companyId = basename($companyDirectory);
            if (! ctype_digit($companyId)) {
                continue;
            }

            foreach ($disk->directories($companyDirectory) as $batchDirectory) {
                $batchId = basename($batchDirectory);
                if (! ctype_digit($batchId)) {
                    continue;
                }

                if (DocumentAiBatch::query()->whereKey((int) $batchId)->exists()) {
                    continue;
                }

                $ageSeconds = self::resolveDirectoryAgeSeconds($batchDirectory);
                if ($ageSeconds === null || $ageSeconds < self::ORPHAN_DIRECTORY_GRACE_SECONDS) {
                    continue;
                }

                $disk->deleteDirectory($batchDirectory);
                $removed++;
            }

            if ($disk->exists($companyDirectory) && $disk->directories($companyDirectory) === [] && $disk->files($companyDirectory) === []) {
                $disk->deleteDirectory($companyDirectory);
            }
        }

        return $removed;
    }

    /**
     * @return int|null Age in seconds, or null when age cannot be determined safely.
     */
    public static function resolveDirectoryAgeSeconds(string $directory): ?int
    {
        $disk = Storage::disk('local');
        $latest = null;

        try {
            foreach ($disk->allFiles($directory) as $file) {
                $modified = $disk->lastModified($file);
                $latest = max($latest ?? 0, $modified);
            }
        } catch (Throwable) {
            return null;
        }

        if ($latest === null) {
            try {
                $path = $disk->path($directory);
                if (! is_dir($path)) {
                    return null;
                }

                $mtime = @filemtime($path);
                if ($mtime === false) {
                    return null;
                }

                $latest = $mtime;
            } catch (Throwable) {
                return null;
            }
        }

        return max(0, time() - $latest);
    }
}
