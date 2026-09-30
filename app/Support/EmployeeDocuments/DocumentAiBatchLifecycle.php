<?php

namespace App\Support\EmployeeDocuments;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DocumentAiBatchLifecycle
{
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

    public static function deleteTemporaryFiles(DocumentAiBatch $batch): void
    {
        $disk = Storage::disk('local');
        $batch->loadMissing('items');

        foreach ($batch->items as $item) {
            $path = (string) $item->temporary_file_reference;
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }

            if (! self::isOwnedTemporaryPath($batch, $path)) {
                continue;
            }

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
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
}
