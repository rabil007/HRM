<?php

namespace App\Jobs;

use App\Enums\DocumentAiBatchItemStatus;
use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Processes queued Document AI batch items concurrently so a single worker
 * can extract multiple files in parallel (wall-clock ≈ slowest item per wave).
 *
 * Per-item ExtractDocumentAiBatchItemJob remains the atomic unit of work and
 * is still dispatched as a recovery fallback for multi-worker setups.
 */
class ProcessDocumentAiBatchInParallelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Cover ceil(max_files / concurrency) waves under the per-item 45s timeout.
     * Must stay below database queue retry_after (default 660s).
     */
    public int $timeout = 300;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = DocumentAiBatch::query()->with('items')->find($this->batchId);
        if ($batch === null) {
            return;
        }

        if (in_array($batch->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
            return;
        }

        $concurrency = max(1, (int) config('document-ai.batch_concurrency', 3));
        $queuedIds = $batch->items
            ->filter(fn ($item) => $item->status === DocumentAiBatchItemStatus::Queued)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($queuedIds->isEmpty()) {
            return;
        }

        foreach ($queuedIds->chunk($concurrency) as $chunk) {
            $batch->refresh();
            if (in_array($batch->status, [DocumentAiBatchStatus::Cancelled, DocumentAiBatchStatus::Expired], true)) {
                return;
            }

            $tasks = [];
            foreach ($chunk->values()->all() as $itemId) {
                $tasks[] = static function () use ($itemId): void {
                    try {
                        ExtractDocumentAiBatchItemJob::dispatchSync($itemId);
                    } catch (Throwable) {
                        // Per-item job records failure/retry; keep sibling extractions running.
                    }
                };
            }

            Concurrency::run($tasks);
        }
    }
}
