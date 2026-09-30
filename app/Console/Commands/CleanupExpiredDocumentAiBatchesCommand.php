<?php

namespace App\Console\Commands;

use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatch;
use App\Support\EmployeeDocuments\DocumentAiBatchLifecycle;
use Illuminate\Console\Command;

class CleanupExpiredDocumentAiBatchesCommand extends Command
{
    protected $signature = 'documents:cleanup-ai-batches';

    protected $description = 'Remove expired Document AI temporary batches and orphan temp directories';

    public function handle(): int
    {
        DocumentAiBatch::query()
            ->where('expires_at', '<=', now())
            ->with('items')
            ->chunkById(100, function ($batches): void {
                foreach ($batches as $batch) {
                    $hasRecentProcessing = $batch->items->contains(
                        fn ($item): bool => DocumentAiBatchLifecycle::isRecentProcessing($item),
                    );

                    if ($hasRecentProcessing) {
                        continue;
                    }

                    DocumentAiBatchLifecycle::terminalizeStaleProcessing($batch);

                    $batch->update(['status' => DocumentAiBatchStatus::Expired]);
                    DocumentAiBatchLifecycle::purge($batch);
                }
            });

        DocumentAiBatchLifecycle::purgeOrphanTemporaryDirectories();

        return self::SUCCESS;
    }
}
