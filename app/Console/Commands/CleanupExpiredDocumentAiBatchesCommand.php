<?php

namespace App\Console\Commands;

use App\Enums\DocumentAiBatchStatus;
use App\Models\DocumentAiBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredDocumentAiBatchesCommand extends Command
{
    protected $signature = 'documents:cleanup-ai-batches';

    protected $description = 'Remove expired Document AI temporary batches';

    public function handle(): int
    {
        DocumentAiBatch::query()->where('expires_at', '<=', now())->with('items')->chunkById(100, function ($batches): void {
            foreach ($batches as $batch) {
                foreach ($batch->items as $item) {
                    Storage::disk('local')->delete($item->temporary_file_reference);
                } $batch->update(['status' => DocumentAiBatchStatus::Expired]);
                $batch->delete();
            }
        });

        return self::SUCCESS;
    }
}
